=== MyMail Mailgun Integration ===
Contributors: mrkdevelopment
Tags: mandrill, mymail, delivery, deliverymethod, newsletter, email, revaxarts, mymailesp
Requires at least: 3.3
Tested up to: 4.1
Stable tag: 0.3.2
License: GPLv2 or later

== Description ==

> This Plugin requires [MyMail Newsletter Plugin for WordPress](http://rxa.li/mymail?utm_source=Mandrill+integration+for+MyMail)

Uses Mailgun from Rackspace to deliver emails for the [MyMail Newsletter Plugin for WordPress](http://rxa.li/mymail).

== Installation ==

1. Upload the entire `mymail-mailgun-integration` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings => Newsletter => Delivery and select the `Mailgun` tab
4. Enter your credentials
5. Send a testmail

== Changelog ==

= 0.1.0 =

Forked from Mandrill addon

== Upgrade Notice ==

== Additional Info One ==

This Plugin requires [MyMail Newsletter Plugin for WordPress](http://rxa.li/mymail?utm_source=Mandrill+integration+for+MyMail)

This Plugin gets 'opened' and 'failed' stats from Mailgun and integrates into mymail stats. Stats are fired using last timestamps value received from mailgun in wp_mymail_actions table (from opened, and failed events at the moment);

