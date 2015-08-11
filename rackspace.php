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
define('MYMAIL_MAILGUN_DOMAIN', '');
define('MYMAIL_MAILGUN_DIR', WP_PLUGIN_DIR.'/mymail-mailgun-integration');
define('MYMAIL_MAILGUN_URI', plugins_url().'/mymail-mailgun-integration');
define('MYMAIL_MAILGUN_SLUG', 'mymail-mailgun-integration/rackspace.php');

require_once "vendor/autoload.php";

/**
 * Mailgun transport hook code for mymail.
 */
class MyMailMailgun
{

    /**
     * Mailgun instance
     *
     * @var [type]
     */
    protected $mailgun;

    /**
     * Selected Domain
     *
     * @var [type]
     */
    protected $domain;

    /**
     * constructor.
     *
     */
    public function __construct()
    {
        $this->plugin_path = plugin_dir_path( __FILE__ );
        $this->plugin_url  = plugin_dir_url( __FILE__ );

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
    public function init()
    {
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

            add_action('mymail_cron_worker', array( &$this, 'getStats'), 2);
        }
    }

    /**
     * initsend function.
     *
     * uses mymail_initsend hook to set initial settings
     *
     * @access public
     * @param  mixed $mailobject
     * @return void
     */
    public function initsend($mailobject)
    {
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
     * @param  mixed   $mailobject
     * @return $errors
     */
    public function subscriber_errors($errors)
    {
        $errors[] = '[rejected]';

        return $errors;
    }

    /**
     * presend function.
     *
     * uses the mymail_presend hook to apply setttings before each mail
     * @access public
     * @param  mixed $mailobject
     * @return void
     */

    public function presend($mailobject)
    {

        //use pre_send from the main class
        //need the raw email body to send so we use the same option
        $mailobject->pre_send();
    }

    /**
     * dosend function.
     *
     * uses the ymail_dosend hook and triggers the send
     * @access public
     * @param  mixed $mailobject
     * @return void
     */
    public function dosend($mailobject)
    {
        $mailobject->mailer->PreSend();

        $mailer = $mailobject->mailer;

        $mailgun = new \Mailgun\Mailgun(mymail_option(MYMAIL_MAILGUN_ID.'_apikey'));

        $domain = mymail_option(MYMAIL_MAILGUN_ID.'_domain');

        $campaignId = $mailobject->headers['X-MyMail-Campaign'];

        $subscriber = mymail('subscribers')->get_by_mail($mailobject->to[0]);

        $message = array(
                        'o:native-send'   => 'yes',
                        'from'            => $mailobject->from,
                        'subject'         => $mailobject->subject,
                        'h:Reply-To'      => $mailobject->reply_to,
                        'html'            => $mailobject->content,
                        'text'            => $mailobject->plaintext,
                        'o:tracking'      => 'yes',
                        'o:tag'           => array("campaign-" . $campaignId, 'subscriber-' . $subscriber->ID, 'mymail'),
                      );

        if ($track = mymail_option(MYMAIL_MAILGUN_ID.'_track')) {
            $message['h:X-Mailgun-Tag'] = $track;
        }

        $batchSize = 1000;

        $chunks = array_chunk($mailobject->to, $batchSize);

        foreach ($chunks as $i => $chunk) {
            $batchMessage = $mailgun->BatchMessage($domain, false);
            $batchMessage->setMessage($message);

            foreach ($chunk as $recipientEmail) {
                $batchMessage->addToRecipient($recipientEmail);
            }

            try {
                $response = $batchMessage->finalize();
            } catch (\Exception $e) {
                $mailobject->set_error($e->getMessage());
                $mailobject->sent = false;

                return;
            }
        }

        $mailobject->sent = true;
    }

    /**
     * Get stats from mailgun by looking for tags.
     *
     * We use 3 tags per message.
     * One related to campaign ID, other subscriber ID and last a generic 'mymail'
     *
     * Last executed timestamp is stored in mymail config/options
     *
     * @return [type] [description]
     */
    public function getStats()
    {
        $this->initMailgun();

        $beginTimestamp = $this->getLastExecutedStatsTimestamp() + 1;

        $hasItems = 100;
        $next     = "";

        $types = array('opened', 'failed');

        $eventTypes = implode(' OR ', $types);

        while ($hasItems == 100) {
            $endTimestamp = current_time('timestamp', 0); //UTC

            list($hasItems, $next) = $this->fetchAndProcessStats($beginTimestamp, $eventTypes, $next);
        }
    }

    /**
     * GET /events from mailgun and updates the mymail_actions table.
     *
     *
     *
     * @param int    $begin     timestamp of the last saved event in mymail_actions. We do not consider 'sent' event as the timestamp is generated by WP and might not be in sync with mailgun
     * @param string $eventType event filter for mailgun
     * @param string $next      next url fetched from mailgun pagination.
     *
     * @return array count of items processed and next uri
     */
    public function fetchAndProcessStats($begin, $eventTypes, $next)
    {
        global $wpdb;

        if (empty($next)) {
            $uri  = $this->domain . "/events";
            $args = array('begin' => $begin, 'ascending' => 'yes', 'event' => $eventTypes , 'tags' => 'mymail' );
        } else {
            $uri  = $next;
            $args = array();
        }

        try {
            $response = $this->mailgun->get( $uri, $args );
        } catch (\Exception $e) {
            return array(0, null);
        }

        $this->dump('response', $response);

        foreach ($response->http_response_body->items as $item) {
            $data                                              = array('timestamp' => (int) $item->timestamp, 'count' => 1);
            list($data['campaign_id'], $data['subscriber_id']) = $this->pullSubscriberDataFromTags($item);

            $type = null;

            switch ($item->event) {
                case 'failed':
                    $type = 7;
                    if ($item->reason == 'bounce') {
                        if ($item->severity == 'permanent') {
                            $type = 6;
                        } else {
                            $type = 5;
                        }
                    }
                    break;

                case 'opened':
                    $type = 2;
                    break;

                // TODO: add other events as per needs.

                default:
                    # should not come here.
                    break;
            }

            $data['type'] = $type;

            $sql = "INSERT INTO {$wpdb->prefix}mymail_actions (".implode(', ', array_keys($data)).")";
            $sql .= " VALUES ('".implode("','", array_values($data))."')";
            try {
                $wpdb->query($sql);
            } catch (\Exception $e) {
            }
        }

        return array(count($response->http_response_body->items), $response->http_response_body->paging->next);
    }

    /**
     * fetch the subscriber data from tags.
     *
     * @param [type] $item [description]
     *
     * @return array campaign id and subscriber id;
     */
    private function pullSubscriberDataFromTags($item)
    {
        $subscriberId = null;
        $campaignId   = null;

        foreach ($item->tags as $tag) {
            if (strpos($tag, 'campaign-') !== false) {
                $campaignId = (int) str_replace('campaign-', '', $tag);
            }
            if (strpos($tag, 'subscriber-') !== false) {
                $subscriberId = (int) str_replace('subscriber-', '', $tag);
            }
        }

        return array($campaignId, $subscriberId);
    }

    /**
     *  Gets the last valid timestamp of event from mailgun saved in actions table.
     *
     * by valid timestamp, we mean all event entries fetched from mailgun events and currently stored in actions table.
     *
     * @return [type] [description]
     */
    public function getLastExecutedStatsTimestamp()
    {
        global $wpdb;
        $allowedTypes = array(2,5,6,7); //types fetched from mailgun
        // we are storing send using mymail so
        $sql    = "select timestamp from {$wpdb->prefix}mymail_actions where type IN (" .implode(',', $allowedTypes) .") order by timestamp desc limit 1";
        $result = $wpdb->get_results( $sql );
        $this->dump('r', $result);

        return empty($result) ? 1 : $result[0]->timestamp;
    }

    /**
     * Helper function
     *
     * @param [type] $lable [description]
     * @param [type] $value [description]
     *
     * @return [type] [description]
     */
    public function dump($lable, $value)
    {
        // echo "<pre>";
        // echo "<b>$lable</b>:";
        // var_dump($value);

        // echo "</pre>";
    }

    /**
     * Initialises the mailgun objects.
     *
     * @return [type] [description]
     */
    public function initMailgun()
    {
        if (!$mailgun) {
            $this->mailgun = new \Mailgun\Mailgun(mymail_option(MYMAIL_MAILGUN_ID.'_apikey'));
            $this->domain  = mymail_option(MYMAIL_MAILGUN_ID.'_domain');
        }
    }

    /**
     * check_bounces function.
     *
     * checks for bounces and reset them if needed
     * @access public
     * @return void
     */
    public function check_bounces()
    {
        $mailer  = $mailobject->mailer;
        $mailgun = new \Mailgun\Mailgun(mymail_option(MYMAIL_MAILGUN_ID.'_apikey'));
        $domain  = mymail_option(MYMAIL_MAILGUN_ID.'_domain');

        $limit = 100;
        $skip  = 0;

        try {
            $response = $mailgun->get("$domain/bounces", array('limit' => $limit, 'skip' => $skip));
        } catch (\Exception $e) {
            return false;
        }

        if ($response->http_response_code == 200) {
            $this->_processHardBounces($response->http_response_body->items, $mailgun);
            $total = $response->http_response_body->total_count;

            $skip = $skip + $limit;
            while ($skip < $total) {
                try {
                    $response = $mailgun->get("$domain/bounces", array('limit' => $limit, 'skip' => $skip));

                    if ($response->http_response_code == 200) {
                        $this->_processHardBounces($response->http_response_body->items, $mailgun);
                    }
                } catch (\Exception $e) {
                    return false;
                }
            }
        }
    }

    /**
     * Process hard bounced subscibers.
     * Remove them from
     *
     * @param [type] $bounces [description]
     *
     * @return [type] [description]
     */
    private function _processHardBounces($bounces, &$mailgun)
    {
        $domain = mymail_option(MYMAIL_MAILGUN_ID.'_domain');

        foreach ($bounces as $item) {
            $email = $item->address;

            $subscriber = mymail('subscribers')->get_by_mail($email);

            mymail('subscribers')->change_status($subscriber->ID, 'hardbounced', true);

            if ($subscriber) {
                try {
                    $mailgun->delete("$domain/bounces/$email");
                } catch (\Exception $e) {
                    // do nothing.
                }
            }
        }
    }

    /**
     * Another way to communicate with mailgun api
     *
     * @param [type] $endpoint [description]
     * @param [type] $bodyonly [description]
     *
     * @return [type] [description]
     */
    public function wpget_call($endpoint, $bodyonly)
    {
        $url = "https://api.mailgun.net/v2/".$endpoint;

        $args = array(
            'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( 'API:' . mymail_option(MYMAIL_MAILGUN_ID.'_apikey') ),
            ),
        );

        $response = wp_remote_get( $url, $args );

        $body = wp_remote_retrieve_body( $response );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response));

        if ($bodyonly) {
            return $body;
        }

        return (object) array(
            'code'    => $code,
            'headers' => wp_remote_retrieve_headers($response),
            'body'    => $body,
        );
    }

    /**
     * do_call function.
     *
     * makes a post request to the mailgun endpoint and returns the result
     * @access public
     * @param  mixed $path
     * @param  array $data     (default: array())
     * @param  bool  $bodyonly (default: false)
     * @param  int   $timeout  (default: 5)
     * @return void
     */
    public function do_call($path, $data = array(), $bodyonly = false, $timeout = 5)
    {
        $url = 'https://api.mailgun.net/v2/'.$path.'.json';
        if (is_bool($data)) {
            $bodyonly = $data;
            $data     = array();
        }
        $data = wp_parse_args($data, array('key' => mymail_option(MYMAIL_MAILGUN_ID.'_apikey')));

        $response = wp_remote_post( $url, array(
            'timeout'   => $timeout,
            'sslverify' => false,
            'body'      => $data,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response));

        if ($code != 200) {
            return new WP_Error($body->name, $body->message);
        }

        if ($bodyonly) {
            return $body;
        }

        return (object) array(
            'code'    => $code,
            'headers' => wp_remote_retrieve_headers($response),
            'body'    => $body,
        );
    }

    /**
     * delivery_method function.
     *
     * add the delivery method to the options
     * @access public
     * @param  mixed $delivery_methods
     * @return void
     */
    public function delivery_method($delivery_methods)
    {
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
    public function deliverytab()
    {
        $verified = mymail_option(MYMAIL_MAILGUN_ID.'_verified');
        $domain   = mymail_option(MYMAIL_MAILGUN_ID.'_domain');

        ?>
		<table class="form-table">

			<?php if (!$verified) : ?>
			<tr valign="top">
				<th scope="row">&nbsp;</th>
				<td><p class="description"><?php echo sprintf(__('You need a %s to use this service!', MYMAIL_MAILGUN_DOMAIN), '<a href="https://mailgun.com/signup/" class="external">mailgun Account</a>');
        ?></p>
				</td>
			</tr>
			<?php endif;
        ?>

			<tr valign="top">
				<th scope="row"><?php _e('Mailgun API Key', MYMAIL_MAILGUN_DOMAIN) ?></th>
				<td><input type="text" name="mymail_options[<?php echo MYMAIL_MAILGUN_ID ?>_apikey]" value="<?php echo esc_attr(mymail_option(MYMAIL_MAILGUN_ID.'_apikey'));
        ?>" class="regular-text" placeholder="xxxxxxxxxxxxxxxxxxxxxx"></td>
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
		<div <?php if (!$verified) {
    echo ' style="display:none"';
}
        ?>>

		<?php if (mymail_option('deliverymethod') == MYMAIL_MAILGUN_ID) : ?>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('Select Domain', MYMAIL_MAILGUN_DOMAIN) ?></th>
				<td>
				<select name="mymail_options[<?php echo MYMAIL_MAILGUN_ID ?>_domain]">
					<option value=""<?php selected(mymail_option(MYMAIL_MAILGUN_ID.'_domain'), 0);
        ?>><?php _e('none', MYMAIL_MAILGUN_DOMAIN);
        ?></option>
					<?php
                            $domains = $this->get_domains();

        print_r($domains);

        if (is_array($domains)) {
            foreach ($domains as $account) {
                echo '<option value="'.$account->name.'" '.selected(mymail_option(MYMAIL_MAILGUN_ID.'_domain'), $account->name, true).'>'.$account->name.($account->state != 'active' ? ' ('.$account->state.')' : '').'</option>';
            }
        } else {
            echo  sprintf('<div class="error inline"><p><strong>%s</strong></p></div>', $domains);
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
		<?php endif;
        ?>
		<input type="hidden" name="mymail_options[<?php echo MYMAIL_MAILGUN_ID ?>_backlog]" value="<?php echo mymail_option( MYMAIL_MAILGUN_ID.'_backlog', 0 ) ?>">
		</div>

	<?php

    }

    /**
     * section_tab_bounce function.
     *
     * displays a note on the bounce tab (MyMail >= 1.6.2)
     * @access public
     * @param  mixed $options
     * @return void
     */
    public function section_tab_bounce()
    {
        ?>
		<div class="error inline"><p><strong><?php _e('Bouncing is handled by Mailgun so all your settings will be ignored', MYMAIL_MAILGUN_DOMAIN);
        ?></strong></p></div>

	<?php

    }

    /**
     * verify_options function.
     *
     * some verification if options are saved
     * @access public
     * @param  mixed $options
     * @return void
     */
    public function verify_options($options)
    {
        if ( $timestamp = wp_next_scheduled( 'mymail_mailgun_cron' ) ) {
            wp_unschedule_event($timestamp, 'mymail_mailgun_cron' );
        }

        //only if delivery method is mailgun
        if ($options['deliverymethod'] == MYMAIL_MAILGUN_ID) {
            if (($options[MYMAIL_MAILGUN_ID.'_apikey'])) {
                $this->mailgun = new \Mailgun\Mailgun($options[MYMAIL_MAILGUN_ID.'_apikey']);

                try {
                    $response = $this->mailgun->get('domains');
                    if ($response->http_response_code == 200) {
                        $verified = true;
                    } else {
                        $verified = false;
                    }
                } catch (Exception $e) {
                    $verified = false;
                }

                $options[MYMAIL_MAILGUN_ID.'_verified'] = $verified;
            }
            if (isset($options[MYMAIL_MAILGUN_ID.'_autoupdate'])) {
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
    public function get_domains()
    {
        $domains = get_transient('mymail_mailgun_domains');

        if (!$domains) {
            $domains = array();
            $this->initMailgun();
            try {
                $response = $this->mailgun->get('domains');
            } catch (Exception $e) {
                return $e->getMessage();
            }
            if ($response->http_response_code == 200) {
                $domains = $response->http_response_body->items;
            }
        }

        set_transient('mymail_mailgun_domains', $domains, 3600);

        return $domains;
    }

    /**
     * update_limits function.
     *
     * Update the limits
     * @access public
     * @return void
     */
    public function update_limits($limits, $update = true)
    {
        if ($update) {
            mymail_update_option('send_limit', $limits['hourly']);
            mymail_update_option('send_period', 1);
            mymail_update_option('send_delay', 0);
            mymail_update_option('send_at_once', min(mymail_option(MYMAIL_MAILGUN_ID.'_send_at_once', 100), max(1, floor($limits['daily']/(1440/mymail_option('interval'))))));
            mymail_update_option(MYMAIL_MAILGUN_ID.'_backlog', $limits['backlog']);
        }
        ($limits['backlog'])
            ? mymail_notice(sprintf(__('You have %s mails in your Backlog! %s', MYMAIL_MAILGUN_DOMAIN), '<strong>'.$limits['backlog'].'</strong>', '<a href="http://eepurl.com/rvxGP" class="external">'.__('What is this?', MYMAIL_MAILGUN_DOMAIN).'</a>'), 'error', true, 'mailgun_backlog')
            : mymail_remove_notice('mailgun_backlog');

        if (!get_transient('_mymail_send_period_timeout')) {
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
    public function notice()
    {
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
    public function settings_scripts_styles()
    {
        global $pagenow;

        if ($pagenow == 'options-general.php' && isset($_REQUEST['page']) && $_REQUEST['page'] == 'newsletter-settings') {
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
    public function activate()
    {
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
    public function deactivate()
    {
        if (defined('MYMAIL_VERSION') && version_compare(MYMAIL_MAILGUN_REQUIRED_VERSION, MYMAIL_VERSION, '<=')) {
            if (mymail_option('deliverymethod') == MYMAIL_MAILGUN_ID) {
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
