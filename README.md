#ff_FeedCleaner

This is a plugin for [Tiny Tiny RSS](https://github.com/gothfox/Tiny-Tiny-RSS). It allows to modify the content of feeds before Tiny Tiny RSS parses them.
Currently, the emphasis is on applying [regular expressions](http://www.php.net/manual/en/book.pcre.php) to the feed data.
The plugin structure is very much inspired by the excellent [af_feedmod](https://github.com/mbirth/ttrss_plugin-af_feedmod) plugin.

The Tiny Tiny RSS version must be 1.8 or later.

##Configuration format changed
With the release 0.8 of ff_FeedCleaner, the configuration format was changed.
Old style configurations should still work at the moment, but all users are encouraged to convert their configurations.
To make this process easier, conversion tools are provided. They are described in [conf_conv.md](https://github.com/wltb/ff_feedcleaner/blob/master/conf_conv.md#converting-the-configuration).

## Installation
This should be done on the command line

```sh
$ cd /var/www/ttrss/plugins
$ git clone https://github.com/wltb/ff_feedcleaner
```

After that, the plugin must be enabled in the preferences of Tiny Tiny RSS.

## Configuration
In the preferences, you'll find a new tab called *FeedCleaner* which contains one large text field which is used to enter/modify the configuration data,
and a checkbox to turn extended logging on and off.
Basically, the configuration data consists of an unnamed [JSON](http://json.org/) array that contains some unnamed JSON objects. Use the **Save** button to store it.

An example configuration looks like this:

```json
[
	{
		"URL": "http://www.iswintercoming.com/feed.php",
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

If an object contains a *URL* and a *URL_re* key, the *URL_re* key is ignored. Generally a *URL_re* key should only be used in specific cases.

It should be noted that the configuration is always UTF-8 encoded.
This may cause problems if the regular expressions contain non-ASCII characters, and the feed encoding is not UTF-8.

###Type *regex*
For this type, two additional keys must be specified: *pattern* and *replacement*.
Their values are used to manipulate the feed data with the [preg_replace function](http://www.php.net/manual/en/function.preg-replace.php).
The semantic of their values is explained on the linked page, in particular, *pattern* is a regular expression.

###Type *xpath_regex*
This type is useful because it allows a more careful selection of the text that should be altered with a [XPath](http://www.w3schools.com/xpath/default.asp) expression. The XPath must be specified with the key *xpath*.
The other keys that are needed are *pattern* and *replacement*. Their meaning is the same as in the *regex* type.

With this type, some subtleties have to be regarded.

1. When the feed is loaded, all five [predefined entities](http://www.w3.org/TR/REC-xml/#sec-predefined-ent) are converted to their real values. When saving, only *&*, *<*, *>* and in attributes also *"* are converted back to *&amp;amp;*, *&amp;lt;*, *&amp;gt;* and *&amp;quot;*, respectively.
2. It may be impossible to apply this type if the feed is not UTF-8 encoded.
3. Further, at the moment only the manipulation of attributes and "leaf tags" (tags that contain no other tags) is supported; or more technically spoken, of nodes that have only one child which has to be a TextNode. This is an implementation detail that may change in future releases.

###Examples
We explain what the entries in the given example configuration do.

In the entry with the URL value *http://www.iswintercoming.com/feed.php*, for any feed whose URL contains *http://www.iswintercoming.com/feed.php*, expressions like *sid=a7595fe6a719361152bb96f8a0bd48b5* (a *sid=* followed by 32 hexadecimal digits) are deleted from the feed data.

The other two entries are useful in conjunction with [af_feedmod](https://github.com/mbirth/ttrss_plugin-af_feedmod).
They amend the article links such that they point to a web page with the full article content, so that it can be fetched instead of a segment.
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

##Extended Logging
Extended logging can be enabled by setting the corresponding checkbox in the preferences tab, or by enabling the global extended logging in Tiny Tiny RSS.
Regardless of these two settings, errors are always logged.
The log entries go into what you have defined in LOG_DESTINATION in Tiny Tiny RSSes config.php.

If you enable extended (local) logging, the activity of the plugin will be reported with great detail. In particular, if you see no output at all, none of your objects in the configuration matched any of your subscribed feeds.

