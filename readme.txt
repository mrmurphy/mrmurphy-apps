=== MrMurphy Apps ===
Contributors: mrmurphy
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Host static HTML/JS/CSS apps at /apps/{slug} with visit tracking.

== Description ==

Upload a zip of static assets for each app and serve it at `/apps/{slug}/` without theme chrome.

Features:

* Zip upload with basic security checks
* Automatic `<base href>` injection for relative asset URLs
* SPA-style fallback to the entry HTML file
* Visit tracking with total, unique, and recent stats

== Installation ==

1. Upload the plugin or symlink it into `wp-content/plugins/mrmurphy-apps`
2. Activate the plugin
3. Create an App, set the slug, upload a zip, and publish
4. Visit `/apps/your-slug/`
