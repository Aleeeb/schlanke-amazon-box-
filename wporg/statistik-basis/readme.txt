=== 1AB Statistik (Arbeitstitel) ===
Contributors: (WordPress.org-Benutzername folgt)
Tags: analytics, statistics, privacy, cookieless, affiliate
Requires at least: 5.8
Tested up to: (vor Einreichung eintragen)
Requires PHP: 7.4
Stable tag: 0.37.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookieless visitor statistics with affiliate click tracking. No cookies, no stored IP addresses, all data stays on your own server.

== Description ==

ENTWURF. Der Text wird vor der Einreichung auf Englisch ausgearbeitet.

* Page views and visitors per day, with comparison to the previous period
* Top pages, referrers, device type
* Countries without any IP lookup (derived from time zone and language)
* Robots are filtered out
* Clicks on affiliate links (Amazon, eBay) per day and per page
* Adjustable data retention

What the plugin does with visitor data: it sets no cookie and stores nothing in the visitor's browser. The IP address is used only in memory to build a short identifier with a random value that is replaced regularly; the IP address itself is never stored. Visitors who send "Do Not Track" are not counted.

== External services ==

None. The plugin does not contact any external server.

== Changelog ==

= 0.37.0 =
* First public draft.
