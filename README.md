#ff_FeedCleaner

This is a plugin for [Tiny Tiny RSS](https://github.com/gothfox/Tiny-Tiny-RSS). It allows to modify the content of feeds before Tiny Tiny RSS parses them.
Currently, the emphasis is on applying [regular expressions](http://www.php.net/manual/en/book.pcre.php) to the feed data.
The plugin structure is very much inspired by the excellent [af_feedmod](https://github.com/mbirth/ttrss_plugin-af_feedmod) plugin.

The Tiny Tiny RSS version must be 1.8 or later.

##Configuration format changed
With the release 0.8 of ff_FeedCleaner, the configuration format was changed,
and as of 0.9, old style configurations won't work anymore.
However, they can be easily converted using [conversion tools](https://github.com/wltb/ff_feedcleaner/blob/master/conf_conv.md#converting-the-configuration).

## Installation
This should be done on the command line

```sh
$ cd /var/www/ttrss/plugins
$ git clone https://github.com/wltb/ff_feedcleaner
```

Alternatively, you can download the zip archive and unzip it into the *plugins* subdirectory of your Tiny Tiny RSS installation.
Note that the directory containing *init.php* **must** be named *ff_feedcleaner*, otherwise Tiny Tiny RSS won't load the plugin, so you may have to rename it after the unzipping.

After that, the plugin must be enabled in the preferences of Tiny Tiny RSS.

## Configuration
In the preferences, you'll find a new tab called *FeedCleaner* which contains one large text field which is used to enter/modify the configuration data,
and two checkboxes to turn

* [extended logging](https://github.com/wltb/ff_feedcleaner#extended-logging)
* [automatic feed correction](https://github.com/wltb/ff_feedcleaner#automatic-feed-correction)

on and off.

Basically, the configuration data consists of an unnamed [JSON](http://json.org/) array that contains some unnamed JSON objects. Use the **Save** button to store it.

An example configuration looks like this:

```json
[
	{
		"URL_re": "#http://(prospector\\.freeforums\\.org|www\\.iswintercoming\\.com)/feed\\.php#",
		"type": "regex",
		"pattern": "/sid=[0-9a-f]{32}/",
		"replacement": ""
	},
	{
		"URL": "newsfeed.zeit.de",
		"type": "xpath_regex",
		"xpath": "//item/link",
		"pattern": "#$#",
		"replacement": "/komplettansicht"
	},
	{
		"URL": "rss.nytimes.com",
		"type": "xpath_regex",
		"xpath": "//item/atom:link/@href|//item/link",
		"pattern": "/$/",
		"replacement": "&pagewanted=all"
	}
]
```

Each object must contain a *type* and a *URL* or a *URL_re* key.

The *type* key specifies which kind of manipulation should be performed on the feed data. The implemented types are described below.

The *URL* and *URL_re* values are used to select the feeds on which the associated manipulation should be applied.
To match, the *URL* value must be a substring of the feed URL.
The *URL_re* value must be a regular expression in the [pcre module style](http://www.php.net/manual/en/book.pcre.php) which is matched against the feed URL with the [preg_match function](http://www.php.net/manual/en/function.preg-match.php).

If an object contains both a *URL* and a *URL_re* key, the *URL_re* key is ignored. Generally a *URL_re* key should only be used in specific cases.
In the example above, the *URL_re* key is used so that the pattern can be applied to more then one domain.

It should be noted that the configuration is always UTF-8 encoded.
This may cause problems if the regular expressions contain non-ASCII characters, and the feed encoding is not UTF-8.
You may then want to convert the feed encoding beforehand with [type uft-8](https://github.com/wltb/ff_feedcleaner#type-utf-8).

###Type *regex*
For this type, two additional keys must be specified: *pattern* and *replacement*.
Their values are used to manipulate the feed data with the [preg_replace function](http://www.php.net/manual/en/function.preg-replace.php).
The semantic of their values is explained on the linked page, in particular, *pattern* is a regular expression.

###Type *xpath_regex*
This type is useful because it allows a more careful selection of the text that should be altered with a [XPath](http://www.w3schools.com/xpath/default.asp) expression. The XPath must be specified with the key *xpath*.
The other keys that are needed are *pattern* and *replacement*. Their meaning is the same as in the *regex* type.

With this type, some subtleties have to be regarded.

1. When the feed is loaded, all five [predefined entities](http://www.w3.org/TR/REC-xml/#sec-predefined-ent) are converted to their real values. When saving, only *&*, *<*, *>* (and in attributes, also *"*) are converted back to *&amp;amp;*, *&amp;lt;*, *&amp;gt;* (and *&amp;quot;*), respectively.
2. On all text nodes that are gathered with the XPath, the regular expression with *pattern* and *replacement* is directly applied. For all other node types, the regular expression is applied on all their children that are text nodes.

###Examples
We explain what the entries in the given example configuration do.

In the entry with the URL_re key, for any feed whose URL contains *http://www.iswintercoming.com/feed.php* or *http://prospector.freeforums.org/feed.php*, expressions like *sid=a7595fe6a719361152bb96f8a0bd48b5* (a *sid=* followed by 32 hexadecimal digits) are removed from the feed data.

The other two entries are useful in conjunction with [af_feedmod](https://github.com/mbirth/ttrss_plugin-af_feedmod).
They amend the article links such that they point to a web page with the full article content, so that it can be fetched instead of just a segment.
It is instructive to compare the two objects with their *regex* cousins that were featured in an earlier version of this document.

```json
[
	{
		"URL": "newsfeed.zeit.de",
		"type": "regex",
		"pattern": "#(<link>http://www\\.zeit\\.de/.+?)(</link>)#",
		"replacement": "$1/komplettansicht$2"
	},
	{
		"URL": "rss.nytimes.com",
		"type": "regex",
		"pattern": "#(<atom:link href=\"http://www.nytimes.com/[^\"]+?)(\")#",
		"replacement": "$1&amp;pagewanted=all$2"
	}
]
```

While these do (roughly) the same, the regular expressions for the *xpath_regex* objects are clearly more elegant, at the expense of finding a nice XPath, of course.
In the NY Times objects, we also can see the different ways of inserting a "&amp;amp;" into the feed. In the *regex* case, this has to be done literally, with the *xpath_regex*, there must be a "&" in the *replacement* value.

###Type *utf-8*
This type converts the feed data encoding to UTF-8.

##Extended Logging
Extended logging is enabled by setting the corresponding checkbox in the preferences tab, or by enabling global extended logging in Tiny Tiny RSS.
Regardless of these two settings, errors are always logged.
The log entries go into what you have defined in LOG_DESTINATION in Tiny Tiny RSSes config.php.

If you enable extended logging, the activity of the plugin will be reported in great detail. In particular, if you see no output at all, none of your objects in the configuration matched any of your subscribed feeds.

##Automatic feed correction
If enabled, this option tries to automatically correct faulty feed data.
Currently, it does this by trying to load the feed into a [DOMDocument](http://www.php.net/manual/en/book.dom.php),
and applies encoding conversion if error 32 and the removal of dangling bytes and invalid Unicode characters if error 9 is detected.
Any remaining fatal errors will appear in Tiny Tiny RSSes log.

It is recommended to enable this option if a type like *xpath_regex* is in use.

