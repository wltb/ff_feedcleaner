#Converting the configuration
The configuration format has changed in 0.8 and old style configurations will not work anymore in 0.9. However, utilities for converting old style configurations are provided.

##Conversion
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

The conversion script, when encountering objects like this, does the following: If the object has neither a *URL* nor a *URL_re* entry and the object key is non-numeric, the key is stored into the *URL_re* entry, and the object key is ommited.

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

Note that the author prefers his */* to be unescaped. You can change that in line 74 of *converter.php*.

Now to the juicy part: How to execute the conversion script? 
###Nowdoc
The repository includes the file *conv-CLI.php*. You can copy & paste your configuration into this gap

```php
$json = <<<'EOT'

EOT;
```

in it and then execute the file on the command line. It should then output a configuration in the new fashion, which you can copy & paste to Tiny Tiny RSS and store it.

###In Tiny Tiny RSS
The conversion can be automated by setting the variable *$CONVERT* at the beginning of the *ff_FeedCleaner* class in *init.php* to *true*. It will convert the configurations of all users.
The *before* object is logged, but you are nonetheless encouraged to backup your configuration beforehand.
Multiple conversions shouldn't change the converted configuration, but you may want to set *$CONVERT* back to *false* to reduce the clutter in your log.
