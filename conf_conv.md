#Converting the configuration

The configuration format has changed in the latest release. To make the conversion as painless as possible, the old configuration style is supported in a sensible way, and a utility for converting old style configurations is included.

##Compatibility notes
Older configurations may look like this

```json
{
	"#^http://www\\.iswintercoming\\.com/feed\\.php#" : {
		"type" : "regex",
		"pattern" : "/sid=[0-9a-f]{32}/",
		"replacement" : ""
	},
	"#^http://rss\\.nytimes\\.com/#" : {
		"type" : "regex",
		"pattern" : "#(<atom:link href=\"http://www.nytimes.com/[^\"]+?)(\")#",
		"replacement" : "$1&amp;pagewanted=all$2"
	}
}
```

The inner objects have keys and no *URL* or *URL_re* entries. If such objects are encountered, the following rules apply:

* If the object has a *URL_re* entry, the object key is ignored.
* If the object has no *URL_re* entry and the object key is non-numeric, the *URL_re* entry is set to the object key, **but only temporary**. The configuration is **not** altered.
* An eventual *URL* key always takes precedence over a *URL_re* key, even if the *URL_re* key was derived from an object key.

##Conversion
Here the included conversion options are described. Note that the author prefers his */* to be unescaped. You can change that in line 74 of *converter.php*.

The conversion roughly follows the same rules as in the compatibility notes.
That is, if an object is encountered that has neither a *URL* nor a *URL_re* entry and the object key is non-numeric,
it is stored as the *URL_re* entry, and the so modified object doesn't have an object key after that.

The converted example configuration from above hence is

```json
[
	{
		"URL_re": "#^http://www\\.iswintercoming\\.com/feed\\.php#",
		"type": "regex",
		"pattern": "/sid=[0-9a-f]{32}/",
		"replacement": ""
	},
	{
		"URL_re": "#^http://rss\\.nytimes\\.com/#",
		"type": "regex",
		"pattern": "#(<atom:link href=\"http://www.nytimes.com/[^\"]+?)(\")#",
		"replacement": "$1&amp;pagewanted=all$2"
	}
]
```

###Nowdoc
The repository includes the file *conv-CLI.php*. You can copy & paste your configuration into this gap

```php
$json = <<<'EOT'

EOT;
```

and execute the file on the command line. It should then output a configuration in the new fashion, which you can copy & paste to Tiny Tiny RSS and store it.

###In Tiny Tiny RSS
The conversion can be automated by setting the variable *$CONVERT* at the beginning of the *ff_FeedCleaner* class in *init.php* to *true*. It will convert the configurations of all users.
The converted object is logged, but you are nonetheless encouraged to backup your configuration beforehand.
Multiple conversions shouldn't change the converted configuration, but you may want to set *$CONVERT* back to *false* to reduce the clutter in your log.
