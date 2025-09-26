# Tiny Tiny RSS plugin ff_feedcleaner
## Introduction
This is a plugin for [Tiny Tiny RSS](https://tt-rss.org/). It allows to modify the content of feeds before Tiny Tiny RSS parses them.
Currently, the emphasis is on applying [regular expressions](http://www.php.net/manual/en/book.pcre.php) to the feed data.

## Installation
This should be done on the command line

```sh
$ cd /var/www/ttrss/plugins.local
$ git clone https://github.com/wltb/ff_feedcleaner
```

Alternatively, you can download the zip archive and unzip it into the *plugins.local* (or *plugins*) subdirectory of your Tiny Tiny RSS installation.
Note that the directory containing *init.php* **must** be named *ff_feedcleaner*, otherwise Tiny Tiny RSS won't load the plugin, so you may have to rename it after the unzipping.

After that, the plugin must be enabled in the preferences of Tiny Tiny RSS.

### Updating

This is easiest with a git installation, simply use a *git pull*.
With a zip installation, the old directory must be overwritten with the a fresh download.
In neither case any stored configurations will be overwritten since these are stored in the database.

## Configuration
In the preferences, you'll find a new tab called *FeedCleaner* which contains two panes. The pane *Preferences* is opened by default, and contains a large text field which is used to enter/modify the configuration data,
and a checkbox to turn [extended logging](https://github.com/wltb/ff_feedcleaner#extended-logging) on and off.
The other pane *Show Diff* can be used to preview the results on the manipulation and is described [further below](#preview-of-modifications).

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
	},
	{
		"URL": "faz.net",
		"type": "link_regex",
		"pattern": "#$#",
		"replacement": "?printPagedArticle=true"
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

### Type *regex*
For this type, two additional keys must be specified: *pattern* and *replacement*.
Their values are used to manipulate the feed data with the [preg_replace function](http://www.php.net/manual/en/function.preg-replace.php).
The semantic of their values is explained on the linked page, in particular, *pattern* is a regular expression.

### Type *xpath_regex*
This type is useful because it allows a more careful selection of the text that should be altered with a [XPath](http://www.w3schools.com/xsl/xpath_intro.asp) expression. The XPath must be specified with the key *xpath*.
The other keys that are needed are *pattern* and *replacement*. Their meaning is the same as in the *regex* type.

With this type, some subtleties have to be regarded.

1. When the feed is loaded, all five [predefined entities](http://www.w3.org/TR/REC-xml/#sec-predefined-ent) are converted to their real values. When saving, only *&*, *<*, *>* (and in attributes, also *"*) are converted back to *&amp;amp;*, *&amp;lt;*, *&amp;gt;* (and *&amp;quot;*), respectively.
2. On all text nodes that are gathered with the XPath, the regular expression with *pattern* and *replacement* is directly applied. For all other node types, the regular expression is applied to all their children that are text nodes.

#### Namespaces
For feeds with default namespaces (e.g. Atom and RDF documents),
querying with xpaths was possible, but a bit of a pain.
In order to ease this, support for namespaces was added.
The software tries to detect if the document has one of the following
default namespaces

* http://www.w3.org/2005/Atom
* http://purl.org/rss/1.0/
* http://purl.org/atom/ns#

and if it does, registers it under the prefix *DNS* (Default Name Space).
Xpath queries then simply have to be prefixed,
e.g. to query for the feed entries in an Atom feed,
the query string can be *"//DNS:feed//DNS:entry"*.

If more namespaces are needed, or the automatic detection gets it wrong,
you can register namespaces manually.
To do so, simply put a *namespaces* entry in the configuration object:

```json
{
	"URL" : …,
	"type" : "xpath_regex",
	…
	"namespaces" : {"pre1": "uri1", "pre2": "uri2"}
}
```

The *uri* entries should be the namespace URIs,
and the *pre* entries are the prefixes to be used in the path queries.

If the *namespaces* key is present, the automatic namespace detection is disabled.

**Important**:
Since this plugin uses the DOMXPath object that is constructed by the FeedParser class of Tiny Tiny RSS, there are already some registered namespaces, and you should **not** override their prefixes. Check the definition of the FeedParser class for details.

### Type link\_regex
This type allows an easy manipulation of the links that Tiny Tiny RSS extracts for the feed items.
The only keys needed beside the obligatory ones are *pattern* and *replacement*, which have the same meaning as in the regex case. But here, they are applied to the links only, and the results are stored so that Tiny Tiny RSS will choose them as the items links.

A warning: This type very much relies on information about Tiny Tiny RSS'es implementation details, and may break if they change. It is however highly unlikely that a change will make this type of manipulation impossible (or that there will be any change at all – the relevant code has been stable for nearly four years now), and I will try my best to quickly adapt to any change.

### Examples
We explain what the entries in the given example configuration do.

In the entry with the URL_re key, for any feed whose URL contains *http://www.iswintercoming.com/feed.php* or *http://prospector.freeforums.org/feed.php*, expressions like *sid=a7595fe6a719361152bb96f8a0bd48b5* (a *sid=* followed by 32 hexadecimal digits) are removed from the feed data.

The other three entries are useful in conjunction with [af_feedmod](https://github.com/mbirth/ttrss_plugin-af_feedmod).
They amend the article links such that they point to a web page with the full article content, so that it can be fetched instead of just a segment.
It is instructive to compare the first two objects with their *regex* cousins that were featured in an earlier version of this document.

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

For the object modifying faz.net feeds, we see that the *link_regex* type works without the need for finding the right xpath, and so combines the best features of the other two types.
Since this type also uses DOM functions, the same rules for insertion of entities as for the *xpath_regex* type apply.

### Type *utf-8*
This type converts the feed data encoding to UTF-8.

## Preview of modifications
The other pane titled *Show Diff* can be used to preview the changes made by this plugin.
Simply enter the feed URL in the text box at the very top and click the button.
A diff of the before and after will show up in the larger text box. Here is a screenshot for the faz.net modification:

![Show Diff](https://cloud.githubusercontent.com/assets/4541137/13574263/abcb7b7c-e483-11e5-877e-5e73e63c8f72.jpg)

The config used for the feed modification is *not* the one stored in the DB, but the one displayed under the **Preferences** pane.
This is useful for trying out new modifications without interfering with the actual fetching, but the text must be valid JSON then.

This feature is still a little rough around the edges, e.g. there may be problems for feeds with encoding other then utf-8, or when the original feed can not be parsed, but the modified one can.

Some technical details: Currently, the unix diff CLI tool is used, so on machines without it, this will not work.
The right hand side (marked with **+** in the screenshot) is the modified feed, and the left hand side the original one.
The diff command used can be changed (for more context et. al.) by overriding the environment variable *DIFF_CMD*.
The preview does not load any other plugins, so the actual parse result in Tiny Tiny RSS may have other modifications besides the one shown in the preview.

## Logging to the feed debugger
Changes applied to a feed are logged to Tiny Tiny RSS'es feed debugger, which can be accessed by a right click on the feed in the main UI, or by pressing f-D while having the feed selected. Additionally, encountered errors will be logged to the default log destination as *E\_USER_WARNING*s.


The log messages are escaped with [htmlspecialchars](https://www.php.net/manual/function.htmlspecialchars.php) to prepare them for html output (which is used by the feed debugger or the built-in System Log). For other log destinations, e.g. syslog or a web server log, the messages may look a bit strange then, but this shouldn't happen too often anyway.
