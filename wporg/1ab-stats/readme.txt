=== 1AB Stats ===
Contributors: WPORG_USERNAME
Tags: analytics, statistics, privacy, cookieless, affiliate
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookieless visitor statistics with affiliate click tracking, heatmaps and an A/B test for read-next cards. All data stays on your own server.

== Description ==

1AB Stats shows how many people visit your site, which pages they read, where they come from, and how often they click your affiliate links. It was built for small content sites that earn money with affiliate links and do not want to send visitor data to anyone else.

**What you get**

* Page views and visitors per day, compared with the previous period
* Most viewed pages
* Referring websites
* Countries, estimated from the browser's time zone and language, without looking up the address
* Device type (phone, tablet, computer)
* Clicks on affiliate links per day, per page and per target. Links to Amazon and eBay are recognised automatically, and you can add the domains of other networks.
* Heatmap for every page: how far visitors read, where they spend time and where they click
* Read-next cards below your posts, chosen by shared tags and categories
* An A/B test for these cards: try another heading, look or place and see which variant gets more clicks, with a plain verdict
* Buttons, images and cards you can place on the home page, in any page or post and in the sidebar (block, shortcode or classic widget), each with its own A/B test of two variants
* A retention period you choose yourself

**How it treats your visitors**

* It sets no cookie and stores nothing in the visitor's browser.
* The visitor's address is never written to the database. It is used in memory only, together with the browser name and a random value, to form a short one-way identifier. The random value is replaced every day by default, so identifiers from earlier days can no longer be matched.
* Browsers that send "Do Not Track" are not counted.
* Logged-in users, previews and robots are not counted.
* Heatmaps and A/B tests store plain totals only (per page, day and device class, or per variant and day), nothing about single visitors. The test variant is drawn by chance in the browser for every page view and is not remembered.
* All statistics stay in your own WordPress database. Nothing about your visitors is ever sent to another server.

Whether you need a consent banner depends on the law that applies to you and on how you run your site. This plugin describes exactly what it does so that you can decide; it does not give legal advice.

**Languages**

English and German.

== Installation ==

1. Install and activate the plugin.
2. Open "1AB Stats" in the dashboard menu. Counting starts right away, there is nothing to set up.
3. Optional: under "Settings" choose a retention period and add the domains of further affiliate networks.

== External services ==

This plugin uses the Freemius SDK to offer the optional Pro version and, only if you agree, to send you update and security notices.

After activation you are asked once whether you want to opt in. If you skip, nothing is sent and the plugin works just the same. If you opt in, Freemius receives your name and email address, your site address, the WordPress, PHP and plugin versions, and the events activation, deactivation and uninstall. Data about your visitors is never sent.

Freemius: [Terms of Service](https://freemius.com/terms/), [Privacy Policy](https://freemius.com/privacy/).

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

= How do I open a heatmap? =

In the overview, click "Heatmap" next to a page in the list of most viewed pages. The page opens with coloured stripes and click dots laid over it. Only administrators can see this.

= How does the A/B test work? =

Under "Read next and A/B test" you set up variant A and variant B of the read-next box and tick "Run the A/B test". Half of the page views then get variant B. The result table shows page views, clicks and click rate for both, and tells you in plain words whether one of them is clearly ahead. Search engines always get variant A.

= How do I test a button, an image or a card? =

Open 1AB Stats, tab "Buttons, images and cards", and add an element. Fill in variant A and variant B and tick "Run the A/B test". Then place the element with the block "1AB Stats element" or with its shortcode. Half of the page views get variant B. Search engines and visitors without JavaScript always get variant A.

= What happens to the data when I delete the plugin? =

All tables and settings are removed.

== Screenshots ==

1. Overview with page views, visitors and affiliate link clicks.
2. Heatmap laid over an article.
3. Read-next cards with the result of an A/B test.
4. Settings.

== Changelog ==

= 1.0.0 =
* First release.
