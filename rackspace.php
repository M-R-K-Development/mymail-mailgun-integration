<?php
/*
Plugin Name: MyMail Mailgun Integration
Plugin URI: http://rxa.li/mymail
Description: Uses Mailgun to deliver emails for the MyMail Newsletter Plugin for WordPress.
This requires at least version 2.0 of the plugin
Version: 0.1.0
Author: mrkdevelopment.com
Author URI: http://mrkdevelopment.com
License: GPLv2 or later
*/


define('MYMAIL_MAILGUN_VERSION', '0.1.0');
define('MYMAIL_MAILGUN_REQUIRED_VERSION', '2.0');
define('MYMAIL_MAILGUN_ID', 'mailgun');
define('MYMAIL_MAILGUN_DOMAIN', 'mymail-mailgun');
define('MYMAIL_MAILGUN_DIR', WP_PLUGIN_DIR.'/mymail-mailgun-integration');
define('MYMAIL_MAILGUN_URI', plugins_url().'/mymail-mailgun-integration');
define('MYMAIL_MAILGUN_SLUG', 'mymail-mailgun-integration/rackspace.php');


class MyMailMailgun {

	
	public function __construct(){

		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array(&$this, 'activate') );
		register_deactivation_hook( __FILE__, array(&$this, 'deactivate') );
		
		load_plugin_textdomain( 'mymail-mailgun', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );
		
		add_action( 'init', array( &$this, 'init'), 1 );
	}

	/**
	 * init function.
	 * 
	 * init the plugin
	 *
	 * @access public
	 * @return void
	 */
	public function init() {


		if (!defined('MYMAIL_VERSION') || version_compare(MYMAIL_MAILGUN_REQUIRED_VERSION, MYMAIL_VERSION, '>')) {

			add_action('admin_notices', array(&$this, 'notice'));
			
		} else {
		
			add_filter('mymail_delivery_methods', array(&$this, 'delivery_method'));
			add_action('mymail_deliverymethod_tab_mailgun', array(&$this, 'deliverytab'));
			
			add_filter('mymail_verify_options', array(&$this, 'verify_options'));

			if (mymail_option('deliverymethod') == MYMAIL_MAILGUN_ID) {

				add_action('mymail_initsend', array(&$this, 'initsend'));
				add_action('mymail_presend', array(&$this, 'presend'));
				add_action('mymail_dosend', array(&$this, 'dosend'));
				add_action('mymail_cron_worker', array(&$this, 'check_bounces'), -1);
				add_action('mymail_check_bounces', array(&$this, 'check_bounces'));

				add_filter('mymail_subscriber_errors', array(&$this, 'subscriber_errors'));
				add_action('mymail_section_tab_bounce', array(&$this, 'section_tab_bounce'));
			}

			add_action('admin_init', array(&$this, 'settings_scripts_styles'));

		}
		
	}


	/**
	 * initsend function.
	 * 
	 * uses mymail_initsend hook to set initial settings
	 *
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	public function initsend($mailobject) {

		$mailobject->dkim = false;
		
		(!defined('MYMAIL_DOING_CRON') && mymail_option(MYMAIL_MAILGUN_ID.'_backlog'))
			? mymail_notice(sprintf(__('You have %s mails in your Backlog! %s', MYMAIL_MAILGUN_DOMAIN), '<strong>'.mymail_option(MYMAIL_MAILGUN_ID.'_backlog').'</strong>', '<a href="http://eepurl.com/rvxGP" class="external">'.__('What is this?', MYMAIL_MAILGUN_DOMAIN).'</a>'), 'error', true, 'mailgun_backlog')
			: mymail_remove_notice('mailgun_backlog');

		
	}


	/**
	 * subscriber_errors function.
	 * 
	 * adds a subscriber error
	 * @access public
	 * @param mixed $mailobject
	 * @return $errors
	 */
	public function subscriber_errors($errors) {
		$errors[] = '[rejected]';
		return $errors;
	}


	/**
	 * presend function.
	 * 
	 * uses the mymail_presend hook to apply setttings before each mail
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	 
	 
	public function presend($mailobject) {
		
		//use pre_send from the main class
		//need the raw email body to send so we use the same option
		$mailobject->pre_send();
		
		if($track = mymail_option(MYMAIL_MAILGUN_ID.'_track')) $mailobject->mailer->addCustomHeader('X-MC-Track', $track);
		
	}


	/**
	 * dosend function.
	 * 
	 * uses the ymail_dosend hook and triggers the send
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	public function dosend($mailobject) {
		

			$mailobject->mailer->PreSend();
			$raw_message = $mailobject->mailer->GetSentMIMEMessage();
			
			$timeout = 15;
			
			$response = $this->do_call('messages/send-raw', array(
				'raw_message' => $raw_message,
				'from_email' => $mailobject->from,
				'from_name' => $mailobject->from_name,
				'to' => $mailobject->to,
				'async' => defined('MYMAIL_DOING_CRON'),
				'ip_pool' => null,
				'return_path_domain' => null,
			), true, $timeout);

			if(is_wp_error($response)){
			
				$mailobject->set_error($response->get_error_message());
				$mailobject->sent = false;
				
			} else {
				
				$response = $response[0];
				if($response->status == 'sent' || $response->status == 'queued'){
					$mailobject->sent = true;
				}else{
					if(in_array($response->reject_reason, array('soft-bounce'))){
					
						//softbounced already so
						$hash = $mailobject->headers['X-MyMail'];
						$camp = $mailobject->headers['X-MyMail-Campaign'];
							
						if($camp && $hash){
							
							$subscriber = mymail('subscribers')->get_by_hash($hash);
						
							$deleteresponse = $this->do_call('rejects/delete', array(
								'email' => $subscriber->email,
								'subaccount' => mymail_option(MYMAIL_MAILGUN_ID.'_subaccount')
							), true);
						
							if(isset($deleteresponse->deleted) && $deleteresponse->deleted){
							
								$this->dosend($mailobject);
						
							}else{
							
								$mailobject->sent = true;
								
							}
							
							
						}else{
						
							$mailobject->set_error('['.$response->status.'] '.$response->reject_reason);
							$mailobject->sent = false;
							
						}
							
						
					}else{
						$mailobject->set_error('['.$response->status.'] '.$response->reject_reason);
						$mailobject->sent = false;
					}
				}
			}
		
	}


	/**
	 * check_bounces function.
	 * 
	 * checks for bounces and reset them if needed
	 * @access public
	 * @return void
	 */
	public function check_bounces() {

			if ( get_transient( 'mymail_check_bounces_lock' ) ) return false;
			
			//check bounces only every five minutes
			set_transient( 'mymail_check_bounces_lock', true, mymail_option('bounce_check', 5)*60 );

			$subaccount = mymail_option(MYMAIL_MAILGUN_ID.'_subaccount', NULL);
			
			$response = $this->do_call('rejects/list', array('subaccount' => $subaccount), true);

			if(is_wp_error($response)){
			
				$response->get_error_message();
				//Stop if there was an error
				return false;
				
			}
			
			if(!empty($response)){
			
				//only the first 100
				$count = 100;
				foreach(array_slice($response, 0, $count) as $subscriberdata){
				
					$subscriber = mymail('subscribers')->get_by_mail($subscriberdata->email);

					//only if user exists
					if($subscriber){

						$reseted = false;
						$campaigns = mymail('subscribers')->get_sent_campaigns($subscriber->ID);

						foreach($campaigns as $i => $campaign){

							//only campaign which have been started maximum a day ago or the last 10 campaigns
							if($campaign->timestamp-strtotime($subscriberdata->created_at)+60*1440 < 0 || $i >= 10) break;

							if(mymail('subscribers')->bounce($subscriber->ID, $campaign->campaign_id, $subscriberdata->reason == 'hard-bounce')){
								$response = $this->do_call('rejects/delete', array(
									'email' => $subscriberdata->email,
									'subaccount' => $subaccount
								), true);
								$reseted = isset($response->deleted) && $response->deleted;
							}

						}

						if(!$reseted){
							$response = $this->do_call('rejects/delete', array(
								'email' => $subscriberdata->email,
								'subaccount' => $subaccount
							), true);
							$reseted = isset($response->deleted) && $response->deleted;
						}

						
					}else{
						//remove user from the list
						$response = $this->do_call('rejects/delete', array(
							'email' => $subscriberdata->email,
							'subaccount' => $subaccount
						));
						$count++;
					}
				}
			}
			
	}


	public function wpget_call($endpoint, $bodyonly){

		$url = "https://api.mailgun.net/v2/".$endpoint;

		$args = array(
		    'headers' => array(
		    'Authorization' => 'Basic ' . base64_encode( 'API:' . mymail_option(MYMAIL_MAILGUN_ID.'_apikey') )
		    )
		);

		$response = wp_remote_get( $url, $args );
		
		$body = wp_remote_retrieve_body( $response );

		if(is_wp_error($response)){
		
			return $response;

		}
		
		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response));
		
		if($bodyonly){
			return $body;
		}
		
		return (object) array(
			'code' => $code,
			'headers' => wp_remote_retrieve_headers($response),
			'body' => $body,
		);
	}


	/**
	 * do_call function.
	 * 
	 * makes a post request to the mailgun endpoint and returns the result
	 * @access public
	 * @param mixed $path
	 * @param array $data (default: array())
	 * @param bool $bodyonly (default: false)
	 * @param int $timeout (default: 5)
	 * @return void
	 */
	public function do_call($path, $data = array(), $bodyonly = false, $timeout = 5) {
		
		$url = 'https://api.mailgun.net/v2/'.$path.'.json';
		if(is_bool($data)){
			$bodyonly = $data;
			$data = array();
		}
		$data = wp_parse_args($data, array('key' => mymail_option(MYMAIL_MAILGUN_ID.'_apikey')));
		
		$response = wp_remote_post( $url, array(
			'timeout' => $timeout,
			'sslverify' => false,
			'body' => $data
		));
		
		if(is_wp_error($response)){
		
			return $response;

		}
		
		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response));
		
		if($code != 200) return new WP_Error($body->name, $body->message);
		
		if($bodyonly) return $body;
		
		return (object) array(
			'code' => $code,
			'headers' => wp_remote_retrieve_headers($response),
			'body' => $body,
		);
		
		
	}


	/**
	 * delivery_method function.
	 * 
	 * add the delivery method to the options
	 * @access public
	 * @param mixed $delivery_methods
	 * @return void
	 */
	public function delivery_method($delivery_methods) {
		$delivery_methods[MYMAIL_MAILGUN_ID] = 'Mailgun';
		return $delivery_methods;
	}


	/**
	 * deliverytab function.
	 * 
	 * the content of the tab for the options
	 * @access public
	 * @return void
	 */
	public function deliverytab() {

		$verified = mymail_option(MYMAIL_MAILGUN_ID.'_verified');
		$domain = mymail_option(MYMAIL_MAILGUN_ID.'_domain');
		
	?>
		<table class="form-table">

			<?php if(!$verified) : ?>
			<tr valign="top">
				<th scope="row">&nbsp;</th>
				<td><p class="description"><?php echo sprintf(__('You need a %s to use this service!', MYMAIL_MAILGUN_DOMAIN), '<a href="https://mailgun.com/signup/" class="external">mailgun Account</a>'); ?></p>
				</td>
			</tr>
			<?php endif; ?>

			<tr valign="top">
				<th scope="row"><?php _e('Mailgun API Key' , MYMAIL_MAILGUN_DOMAIN) ?></th>
				<td><input type="text" name="mymail_options[<?php echo MYMAIL_MAILGUN_ID ?>_apikey]" value="<?php echo esc_attr(mymail_option(MYMAIL_MAILGUN_ID.'_apikey')); ?>" class="regular-text" placeholder="xxxxxxxxxxxxxxxxxxxxxx"></td>
			</tr>
			<tr valign="top">
				<th scope="row">&nbsp;</th> 
				<td>
					<img src="<?php echo MYMAIL_URI . 'assets/img/icons/'.($verified ? 'green' : 'red').'_2x.png'?>" width="16" height="16">
					<?php echo ($verified) ? __('Your API Key is ok!', MYMAIL_MAILGUN_DOMAIN) : __('Your credentials are WRONG!', MYMAIL_MAILGUN_DOMAIN)?>
					<input type="hidden" name="mymail_options[<?php echo MYMAIL_MAILGUN_ID ?>_verified]" value="<?php echo $verified?>">
				</td>
			</tr>
		</table>
		<div <?php if (!$verified) echo ' style="display:none"' ?>>

		<?php if (mymail_option('deliverymethod') == MYMAIL_MAILGUN_ID) : ?>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('Select Domain' , MYMAIL_MAILGUN_DOMAIN) ?></th>
				<td>
				<select name="mymail_options[<?php echo MYMAIL_MAILGUN_ID ?>_domain]">
					<option value=""<?php selected(mymail_option(MYMAIL_MAILGUN_ID.'_domain'), 0); ?>><?php _e('none', MYMAIL_MAILGUN_DOMAIN); ?></option>
					<?php 
							$data= $this->get_domains();
							print_r($data->body->items);
							
							foreach($data->body->items as $account){
								echo '<option value="'.$account->name.'" '.selected(mymail_option(MYMAIL_MAILGUN_ID.'_domain'), $account->name, true).'>'.$account->name.($account->state != 'active' ? ' ('.$account->state.')' : '').'</option>';
							}
							
					?>
				</select>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">&nbsp;</th> 
				<td>
				<img src="<?php echo MYMAIL_URI . 'assets/img/icons/'.($domain ? 'green' : 'red').'_2x.png'?>" width="16" height="16">
					<?php echo ($verified) ? __('Your Domain Name is selected! - '.$domain, MYMAIL_MAILGUN_DOMAIN) : __('You need to select a domain name!', MYMAIL_MAILGUN_DOMAIN)?>
				</td>
			</tr>
		</table>
		<?php endif; ?>
		<input type="hidden" name="mymail_options[<?php echo MYMAIL_MAILGUN_ID ?>_backlog]" value="<?php echo mymail_option( MYMAIL_MAILGUN_ID.'_backlog', 0 ) ?>">
		</div>

	<?php

	}


	/**
	 * section_tab_bounce function.
	 * 
	 * displays a note on the bounce tab (MyMail >= 1.6.2)
	 * @access public
	 * @param mixed $options
	 * @return void
	 */
	public function section_tab_bounce() {
	?>
		<div class="error inline"><p><strong><?php _e('Bouncing is handled by Mailgun so all your settings will be ignored', MYMAIL_MAILGUN_DOMAIN); ?></strong></p></div>

	<?php
	}


	/**
	 * verify_options function.
	 * 
	 * some verification if options are saved
	 * @access public
	 * @param mixed $options
	 * @return void
	 */
	public function verify_options($options) {

		if ( $timestamp = wp_next_scheduled( 'mymail_mailgun_cron' ) ) {
			wp_unschedule_event($timestamp, 'mymail_mailgun_cron' );
		}

		//only if deleiver method is mailgun
		if ($options['deliverymethod'] == MYMAIL_MAILGUN_ID) {

			if (($options[MYMAIL_MAILGUN_ID.'_apikey'])) {

				$response = $this->wpget_call('domains',false);

				if($response->code=='200'){
					$options[MYMAIL_MAILGUN_ID.'_verified'] = true;
				}else{
					$options[MYMAIL_MAILGUN_ID.'_verified'] = false;
				}
			}
			if(isset($options[MYMAIL_MAILGUN_ID.'_autoupdate'])){
				if ( !wp_next_scheduled( 'mymail_mailgun_cron' ) ) {
					wp_schedule_event( time()+3600, 'hourly', 'mymail_mailgun_cron');
				}
			}
		}
		
		return $options;
	}


	/**
	 * get_domains function.
	 * 
	 * get a list of domains
	 * @access public
	 * @return void
	 */
	public function get_domains() {

		if(!($domains = get_transient('mymail_mailgun_domains'))){
			$domains = $this->wpget_call('domains', false);
			if(!is_wp_error($domains)){
				set_transient('mymail_mailgun_domains', $domains, 3600);
			}else{
				$domains = array();
			}
		}
		
		return $domains;
		
	}


	/**
	 * update_limits function.
	 * 
	 * Update the limits
	 * @access public
	 * @return void
	 */
	public function update_limits($limits, $update = true) {
		if($update){
			mymail_update_option('send_limit', $limits['hourly']);
			mymail_update_option('send_period', 1);
			mymail_update_option('send_delay', 0);
			mymail_update_option('send_at_once', min(mymail_option(MYMAIL_MAILGUN_ID.'_send_at_once', 100),max(1, floor($limits['daily']/(1440/mymail_option('interval'))))));
			mymail_update_option(MYMAIL_MAILGUN_ID.'_backlog', $limits['backlog']);
		}
		($limits['backlog'])
			? mymail_notice(sprintf(__('You have %s mails in your Backlog! %s', MYMAIL_MAILGUN_DOMAIN), '<strong>'.$limits['backlog'].'</strong>', '<a href="http://eepurl.com/rvxGP" class="external">'.__('What is this?', MYMAIL_MAILGUN_DOMAIN).'</a>'), 'error', true, 'mailgun_backlog')
			: mymail_remove_notice('mailgun_backlog');
		
		if(!get_transient('_mymail_send_period_timeout')){
			set_transient('_mymail_send_period_timeout', true, $options['send_period']*3600);
		}
		update_option('_transient__mymail_send_period_timeout', $limits['sent'] > 0);
		update_option('_transient__mymail_send_period', $limits['sent']);
	}


	/**
	 * notice function.
	 * 
	 * Notice if MyMail is not available
	 * @access public
	 * @return void
	 */
	public function notice() {
	?>
	<div id="message" class="error">
		<p>
		<strong>Mailgun integration for MyMail</strong> requires the <a href="http://rxa.li/mymail?utm_source=mailgun+integration+for+MyMail">MyMail Newsletter Plugin</a>, at least version <strong><?php echo MYMAIL_MAILGUN_REQUIRED_VERSION?></strong>. Plugin deactivated.
		</p>
	</div>
	<?php
	}


	/**
	 * settings_scripts_styles function.
	 * 
	 * some scripts are needed
	 * @access public
	 * @return void
	 */
	public function settings_scripts_styles() {
		global $pagenow;
		
		if($pagenow == 'options-general.php' && isset($_REQUEST['page']) && $_REQUEST['page'] == 'newsletter-settings'){

			wp_register_script('mymail-mailgun-settings-script', MYMAIL_MAILGUN_URI . '/js/script.js', array('jquery'), MYMAIL_MAILGUN_VERSION);
			wp_enqueue_script('mymail-mailgun-settings-script');
			
		}

	}


	/**
	 * activation function.
	 * 
	 * activate function
	 * @access public
	 * @return void
	 */
	public function activate() {

		if (defined('MYMAIL_VERSION') && version_compare(MYMAIL_MAILGUN_REQUIRED_VERSION, MYMAIL_VERSION, '<=')) {
			mymail_notice(sprintf(__('Change the delivery method on the %s!', MYMAIL_MAILGUN_DOMAIN), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=mymail_delivery_method#delivery">Settings Page</a>'), '', false, 'delivery_method');
			if ( !wp_next_scheduled( 'MYMAIL_MAILGUN_cron' ) ) {
				wp_schedule_event( time(), 'hourly', 'MYMAIL_MAILGUN_cron');
			}
		}
		
	}


	/**
	 * deactivation function.
	 * 
	 * deactivate function
	 * @access public
	 * @return void
	 */
	public function deactivate() {

		if (defined('MYMAIL_VERSION') && version_compare(MYMAIL_MAILGUN_REQUIRED_VERSION, MYMAIL_VERSION, '<=')) {
			if(mymail_option('deliverymethod') == MYMAIL_MAILGUN_ID){
				mymail_update_option('deliverymethod', 'simple');
				mymail_notice(sprintf(__('Change the delivery method on the %s!', MYMAIL_MAILGUN_DOMAIN), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=mymail_delivery_method#delivery">Settings Page</a>'), '', false, 'delivery_method');
			}
			
			if ( $timestamp = wp_next_scheduled( 'MYMAIL_MAILGUN_cron' ) ) {
				wp_unschedule_event($timestamp, 'MYMAIL_MAILGUN_cron' );
			}
		}
		
	}

}

new MyMailMailgun();
?>