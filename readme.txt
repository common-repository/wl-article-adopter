=== WL Article Adopter ===
Contributors: iverok,webloeft
Tags: Shared Articles
Requires at least: 4.4.2
Tested up to: 5.5.1
Stable tag: trunk
License: AGPLv3 or later
License URI: http://www.gnu.org/licenses/agpl-3.0.html

WL Article Adopter is a client for the Shared Article database provided by the Shared Article Repository plugin.

== Description ==

This plugin is a client for a database of shared articles provided by the Shared Article Repository plugin. The client 
allows both publishing local articles to the repository and subscribing ('adopting') articles from the repository. Changes to 
articles will be synchronized automatically.

Articles are shared using the WP REST API.

== Installation ==

1. Get a login with the role "Library" on the Wordpress install where the Shared Article Repository plugin has been installed.
1. Upload the plugin files to the `/wp-content/plugins/article-adopter` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress
1. Use the Settings->WL Article Adopter screen to configure the plugin
1. Log in to your account on the repository instance and paste in your public key on your account there. Return to the settings page and press connect.
1. You can now share articles to the repository by marking the checkbox on the post or page edit screen, and you will have a new menu Article Database for adopting articles from the central repository.


== Frequently Asked Questions ==

None yet.

== Screenshots ==

None yet.

== Changelog ==

= 0.15 =
Testing for latest WP version

= 0.14 =
Fix some warnings

= 0.13 =
Fixed "<?" instead of "<?php".

= 0.12 =
Get subscription count for a shared article when viewing it in admin

= 0.11 =
Automatic subscriptions to shared articles by tag

= 0.10 =
Confirm dialog with copyright warning on share

= 0.09 =
Fix support for categories and tags after the REST api changes in 4.7

= 0.08 =
Internationalization added, with Norwegian added for testing purposes

= 0.07 =
* Provide a log and a way to reset the 'last updated' timestamp

= 0.06 =
* Do elementary math correctly

= 0.05 =
* Restart cronjob if not running, not only on activation

= 0.04 =
* Restored cronjob

= 0.03 =
* Somewhat better error reporting

= 0.02 =
* On sharing, expand shortcodes but not the rest of the 'the_content'-filter

= 0.01 =
* Initial release

