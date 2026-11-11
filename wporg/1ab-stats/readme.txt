=== 1AB Stats ===
Contributors: WPORG_USERNAME
Tags: analytics, statistics, privacy, cookieless, affiliate
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookieless visitor statistics with affiliate click tracking. No cookies, no stored IP addresses, all data stays on your own server.

== Description ==

1AB Stats shows how many people visit your site, which pages they read, where they come from, and how often they click your affiliate links. It was built for small content sites that earn money with affiliate links and do not want to send visitor data to anyone else.

**What you get**

* Page views and visitors per day, compared with the previous period
* Most viewed pages
* Referring websites
* Countries, estimated from the browser's time zone and language, without looking up the address
* Device type (phone, tablet, computer)
* Clicks on affiliate links per day, per page and per target. Links to Amazon and eBay are recognised automatically, and you can add the domains of other networks.
* A retention period you choose yourself

**How it treats your visitors**

* It sets no cookie and stores nothing in the visitor's browser.
* The visitor's address is never written to the database. It is used in memory only, together with the browser name and a random value, to form a short one-way identifier. The random value is replaced every day by default, so identifiers from earlier days can no longer be matched.
* Browsers that send "Do Not Track" are not counted.
* Logged-in users, previews and robots are not counted.
* All data stays in your own WordPress database. The plugin never contacts another server.

Whether you need a consent banner depends on the law that applies to you and on how you run your site. This plugin describes exactly what it does so that you can decide; it does not give legal advice.

**Languages**

English and German.

== Installation ==

1. Install and activate the plugin.
2. Open "1AB Stats" in the dashboard menu. Counting starts right away, there is nothing to set up.
3. Optional: under "Settings" choose a retention period and add the domains of further affiliate networks.

== Frequently Asked Questions ==

= Why do I see no visits of my own? =

Logged-in users are never counted. Open your site in a private window to test.

= Does it work with caching plugins? =

Yes. The counting script is a static file and the report is sent from the visitor's browser, so cached pages are counted as well.

= How are visitors counted without cookies? =

For each report the server forms a short one-way identifier from the visitor's address, the browser name and a random value. Only the identifier is stored. Because the random value is replaced regularly, a visitor cannot be followed over a longer time. Visitors are counted per day; someone who comes on two days counts twice in the total.

= How accurate are the countries? =

They are an estimate from the time zone and language the browser reports. No address database is used. For most visitors this is correct, travellers and people using unusual settings may be assigned to another country.

= My site is behind Cloudflare or another proxy. What do I have to do? =

Tick "Proxy or CDN" in the settings. Otherwise all visitors may look like one.

= What happens to the data when I delete the plugin? =

The table and all settings are removed.

== Screenshots ==

1. Overview with page views, visitors and affiliate link clicks.
2. Settings.

== Changelog ==

= 1.0.0 =
* First release.
