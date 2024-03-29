<?php

class ff_FeedCleaner extends Plugin {
	/** @psalm-suppress PropertyNotSetInConstructor */
	private PluginHost $host;

	function about() {
		return array(
			0.9, // version
			'Applies regular expressions to a feed', // description
			'feader', // author
			false, // is_system
		);
	}

	function api_version() {
		return 2;
	}

	function csrf_ignore($method) {
		return true;
	}

	/** @psalm-suppress MixedArgument */
	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TABS, $this);
		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
		$host->add_hook($host::HOOK_FEED_PARSED, $this);

		Config::add('DIFF_CMD', 'diff -U 0 -s -w');
	}


	protected static function escape(string $msg): string {
		return htmlspecialchars($msg, ENT_NOQUOTES);
	}

	/**
	 * Wrapper for debug messages.
	 *
	 * @param string $msg
	 * @psalm-param 256|512|1024|null $prio
	 * @return void
	 * @see https://www.php.net/manual/errorfunc.constants.php
	 */
	private static function debug(string $msg, ?int $prio=null): void {
		if(! class_exists("Debug")) {
			trigger_error("Debug class doesn't exist. Why?", E_USER_ERROR);
			return;
		}
		$msg = static::escape($msg);

		Debug::log($msg, Debug::LOG_VERBOSE);
		// TODO maybe try to detect the log destination to choose the necessary escaping?
		if($prio) trigger_error($msg, $prio);
	}


	//implement fetch hooks
	function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed) {
		$json_conf = $this->host->get($this, 'json_conf');

		try {
			list($feed_data, $this->feed_parsed) = self::hook1($feed_data, $fetch_url, $json_conf);
		} catch (RuntimeException|JsonException $e) {
			self::debug($e->getMessage(), E_USER_WARNING);
		}

		return $feed_data;
	}

	/**
	 * Match the stored configurations to *$fetch_url*, execute textual ones right away and return
	 * XML mods for later use.
	 *
	 * @param string $feed_data
	 * @param string $fetch_url
	 * @param mixed $json_conf
	 * @return array{0: string, 1: array} first one is eventually modified feed data, second one is array of configs
	 * @throws RuntimeException
	 * @throws JsonException
	 */
	private static function hook1(string $feed_data, string $fetch_url, $json_conf): array {
		$json_conf = self::check_json_conf($json_conf);

		$later = array();
		foreach($json_conf as $config) {
			if(! is_array($config)) continue;

			$url_ = $config['URL'] ?? null;
			$url_re = $config['URL_re'] ?? null;
			if(is_string($url_)) $test = (stripos($fetch_url, $url_) !== false);
			elseif(is_string($url_re)) $test = (preg_match($url_re, $fetch_url) === 1);
			else {
				$msg = sprintf("Config '%s': Neither URL nor URL_re key is present, or wrong type", json_encode($config));
				self::debug($msg, E_USER_WARNING);
				continue;
			}

			if( ! $test ) continue;

			$msg = "Modifying '$fetch_url' using " . json_encode($config);
			switch (strtolower($config["type"] ?? '')) {
			case "regex":
				self::debug($msg);
				$feed_data = self::apply_regex($feed_data, $config);
				break;
			case "xpath_regex":
			case "link_regex":
				$later [] = $config;
				break;
			case "utf-8":
				self::debug($msg);
				$feed_data = self::enc_utf8($feed_data, $config);
				break;
			default:
				continue 2;
			}
		}

		return [$feed_data, $later];
	}


	function hook_feed_parsed($parser, $feed_id) {
		if(! $this->feed_parsed) return;  // TODO rename this !
		self::hook2($parser, $this->feed_parsed);
	}


	private static function hook2(FeedParser $rss, array $config_data): void {
		static $p_xpath;
		if(!$p_xpath) { #initialize reflection
			$ref = new ReflectionClass(FeedParser::class);  # TODO use variable
			$p_xpath = $ref->getProperty('xpath');
			$p_xpath->setAccessible(true);
		}
		/** @var DOMXPath */
		$xpath = $p_xpath->getValue($rss);
		//$xpath->registerNamespace('rssfake', "http://purl.org/rss/1.0/");

		foreach($config_data as $config) {
			$msg = "Modifying '{$rss->get_link()}' using " . json_encode($config);
			switch (strtolower($config["type"])) {
			case "xpath_regex":
				self::debug($msg);
				self::apply_xpath_regex($xpath, $config);
				break;
			case "link_regex":
				self::debug($msg);
				self::link_regex($rss, $config);
				break;
			}
		}
	}


	private static function link_regex(FeedParser $rss, array $config): void {
		//  TODO link may be relative
		//  URLHelper::rewrite_relative($site_url, $item->get_link());

		$counter = 0;
		foreach($rss->get_items() as $item_caps) {
			$url = $item_caps->get_link();
			$new_url = self::apply_regex($url, $config, true);
			if($new_url !== $url) {
				$item = $item_caps->get_element();
				/** @psalm-suppress PossiblyNullReference */
				$link = $item->ownerDocument->createElementNS("http://www.w3.org/2005/Atom", 'link');
				$link->setAttribute('href', $new_url);
				$item->insertBefore($link, $item->firstChild);
				if($new_url !== $item_caps->get_link()) {
					self::debug("'$new_url' wasn't set for " . json_encode($config)
						. ". File an issue please.", E_USER_WARNING);
				} else {
					$counter++;
					#self::debug("Turned '$url' into '$new_url'");  // TODO higher verbosity?
				}
			}
		}

		self::debug("# of modified links: $counter");
	}


	private static function enc_utf8(string $feed_data, array $config): string {
		$decl_regex =
			'/^(<\?xml
				[\t\n\r ]+version[\t\n\r ]*=[\t\n\r ]*["\']1\.[0-9]+["\']
				[\t\n\r ]+encoding[\t\n\r ]*=[\t\n\r ]*["\'])([A-Za-z][A-Za-z0-9._-]*)(["\']
				(?:[\t\n\r ]+standalone[\t\n\r ]*=[\t\n\r ]*["\'](?:yes|no)["\'])?
			[\t\n\r ]*\?>)/x';
		if (preg_match($decl_regex, $feed_data, $matches) === 1 && strtoupper($matches[2]) != 'UTF-8') {
			mb_substitute_character("none");
			$data = mb_convert_encoding($feed_data, 'UTF-8', $matches[2]);
			if($data !== false) {
				$feed_data = preg_replace($decl_regex, $matches[1] . "UTF-8" . $matches[3], $data);
				self::debug('Encoding conversion to UTF-8 was successful');
			} else self::debug('For ' . json_encode($config) . ": Couldn't convert the encoding", E_USER_WARNING);
		}
		else {
			self::debug('No encoding declared or encoding is UTF-8 already');
		}

		return $feed_data;
	}


	private static function apply_regex(string $feed_data, array $config, bool $silent=false): string {
		$pat = $config["pattern"];
		$rep = $config["replacement"];

		$feed_data_mod = preg_replace($pat, $rep, $feed_data, -1, $count);

		if($feed_data_mod !== null) {
			$feed_data = $feed_data_mod;
			if(! $silent) self::debug("Applied (pattern '$pat', replacement '$rep') $count times");
		} else {
			self::debug("Error applying RegEx (pattern '$pat', replacement '$rep')", E_USER_WARNING);
		}

		return $feed_data;
	}


	private static function apply_xpath_regex(DOMXPath $xpath, array $config): void {
		if(isset($config['namespaces']) && is_array($config['namespaces'])) {
			foreach($config['namespaces'] as $prefix => $URI) $xpath->registerNamespace($prefix, $URI);
		} else { //TODO remove this
			$DNS = array(
			"http://www.w3.org/2005/Atom",
			"http://purl.org/rss/1.0/",
			'http://purl.org/atom/ns#',
			);
			foreach($DNS as $URI) {
				if($xpath->document->isDefaultNamespace($URI)) {
					$xpath->registerNamespace("DNS", $URI);
					break;
				}
			}
		}

		$node_list = $xpath->query($config['xpath']);

		$pat = $config["pattern"];
		$rep = $config["replacement"];

		self::debug("Found {$node_list->length} nodes with XPath '{$config['xpath']}'");

		$preg_rep_func = function(DOMNode $node) use ($pat, $rep, &$counter): void {
			if( $node->nodeType == XML_TEXT_NODE) {
				$text_mod = preg_replace($pat, $rep, $node->textContent, -1, $count);
				if($text_mod !== null) {
					$node->nodeValue = $text_mod;
					$counter += $count;
				} else {
					self::debug("Error applying (pattern '$pat', replacement '$rep') to '{$node->textContent}'", E_USER_WARNING);
				}
			}
		};

		$counter = 0;
		foreach($node_list as $node) {
			$preg_rep_func($node);
			if($node->hasChildNodes())
				// This also works for DOMAttributes because apparently,
				// their nodeValue is stored in a TextNode child.
				foreach($node->childNodes as $child) $preg_rep_func($child);
		}

		self::debug("Applied (pattern '$pat', replacement '$rep')  $counter times");
	}

	function get_prefs_js() {
		return file_get_contents(__DIR__ . '/BackendComm.js');
	}

	private const HTML_ID = self::class . 'ConfigTab';

	//gui hook stuff
	function hook_prefs_tabs() {
		?>
<div id="<?= self::HTML_ID;?>" data-dojo-type="dijit/layout/ContentPane"
 data-dojo-props="href: 'backend.php?op=pluginhandler&plugin=<?= strtolower(self::class); ?>'"
 title="<i class='material-icons' style='margin-right: 2px'>brush</i><span>FeedCleaner</span>"></div>;
<script type="text/javascript">
	const fffc_comm = new BackendCommFC("<?= self::class;?>");
</script>

<style>
div#<?= self::HTML_ID;?> #preview {
	border:2px solid grey;
	max-width: 30cm;
}

div#<?= self::HTML_ID;?> .UrlBox.dijitTextBox {
	min-width: 48em;
}

div#<?= self::HTML_ID;?> textarea[name=json_conf] {
	font-size: 10px;
	width: 99%;
	min-height: 550px;
}

div#<?= self::HTML_ID;?> form#feedcleaner_settings table {width: 100%;}
</style>

<?php
	}

	public function index(): void {
		$pluginhost = PluginHost::getInstance();
		$json_conf = $pluginhost->get($this, 'json_conf');

		header("Content-Type: text/html; charset=utf-8");

		?>
<div data-dojo-type="dijit/layout/AccordionContainer" style="height:100%;">
	<div data-dojo-type="dijit/layout/ContentPane" title="<?= __('Preferences'); ?>" selected="true">
	<form data-dojo-type="dijit/form/Form" accept-charset="UTF-8" style="overflow:auto;"
	id="feedcleaner_settings">
	<script type="dojo/method" data-dojo-event="onSubmit" data-dojo-args="evt">
		evt.preventDefault();
		fffc_comm.post_notify("save", {}, this);
	</script>
	<table>
	<tr><td><!-- whitespace is significant in textarea -->
		<textarea data-dojo-type="dijit/form/SimpleTextarea" name="json_conf"
			><?= htmlspecialchars($json_conf, ENT_NOQUOTES, 'UTF-8');?></textarea>
	</td></tr>
	</table>

	<p><button data-dojo-type="dijit/form/Button" type="submit"><?= __("Save");?></button></p>
	</form>
	</div>

	<div data-dojo-type="dijit/layout/ContentPane" title="<?= 'Diff ' . __('Preview'); ?>">
	<form data-dojo-type="dijit/form/Form" id="DiffPreviewForm">
		<script type="dojo/method" data-dojo-event="onSubmit" data-dojo-args="evt">
			evt.preventDefault();
			const ob = {json_conf: document.forms["feedcleaner_settings"].elements["json_conf"].value};

			(async () => {
				const answer = await fffc_comm.post_notify("preview", ob, this);
				const diff = answer?.proc?.diff ?? null;
				const value = diff? diff.join(`\n`): '';  // we do joining on the client because newlines can be platform-dependent

				// we shouldn't use plain DOM here (as we do above) because non-simple Textarea uses special dojo magic
				const preview = dijit.byId("preview");
				preview.set('value', value);
				// dijit.byId is deprecated, this is new style:
				//require(["dijit/registry"], function(registry){registry.byId('preview').set('value', value);})
			})();
		</script>
		<span>URL: </span><input data-dojo-type="dijit/form/TextBox" name="url" type="url" data-dojo-props="class: 'UrlBox'">
		<button data-dojo-type="dijit/form/Button" type="submit"><?= __("Preview"); ?></button>
	</form>
	<section>
		<textarea data-dojo-type="dijit/form/Textarea" id="preview" readonly form="DiffPreviewForm"
		data-dojo-props="placeholder: '<?= __('Preview'); ?>'"></textarea>
	</section>
	</div>
</div>

<?php
	}

	/**
	 * Checks if its argument can be decoded into (useful) JSON, and returns it then. Throws Exceptions when not.
	 *
	 * More checking could be done, even a JSON Validation
	 *
	 * @param mixed $json
	 * @return array
	 * @throws RuntimeException
	 * @throws JsonException
	 * @psalm-assert string $json
	 */
	private static function check_json_conf($json) {
		if(! is_string($json)) throw new RuntimeException("Not a string, can't decode JSON.");
		$arr = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if(! is_array($arr)) throw new RuntimeException("JSON is valid, but not a useful config.");
		return $arr;
	}

	public function save(): void {
		$json_conf = $_POST['json_conf'] ?? null;

		try {
			self::check_json_conf($json_conf);
		} catch (RuntimeException|JsonException $err) {
			$note = $err instanceof JsonException? __("Invalid") . " JSON: ": '';
			echo json_encode(["notifyError" => $note . $err->getMessage()]);
			return;
		}

		$this->host->set($this, 'json_conf', $json_conf);

		echo json_encode(["notify" => __("Configuration saved.")]);
	}

	// diff stuff

	public function preview(): void {
		$url = $_POST['url'] ?? null;
		$conf = $_POST['json_conf'] ?? null;

		if(! is_string($url)) {
			echo json_encode(["notifyError" => __("Invalid URL.")]);
			return;
		}

		try {
			print json_encode(["proc" => ["diff" => self::compute_diff($url, $conf)]]);
		} catch (RuntimeException|JsonException $err) {
			$note = $err instanceof JsonException? __("Invalid") . " JSON: ": '';
			print json_encode(["notifyError" => $note . $err->getMessage()]);
		}
	}

	/**
	 * Stores given XML data to a temp file and returns its file name. Formats the XML nicely by default.
	 *
	 * @psalm-param non-empty-string $data
	 * @param boolean $format
	 * @return string
	 * @throws RuntimeException
	 */
	private static function format_save_tmp(string $data, bool $format=true): string {
		$filename = tempnam(sys_get_temp_dir(), '');
		$xml = new DOMDocument();

		if($format) {
			$xml->preserveWhiteSpace = false;
			$xml->formatOutput = true;
		}
		$res = $xml->loadXML($data);
		$res2 = $xml->save($filename);

		if(! $res || ! $res2) throw new RuntimeException("Couldn't write XML.");

		return $filename;
	}

	# Here used to be a constant that defined the diff command. It can now be changed in the global config file.

	/**
	 * Compute a diff between original feed data found under *$url*,
	 * and the feed after applying all modifications this plugin performs.
	 *
	 * @param string $url
	 * @param mixed $json_data
	 * @return list<string>  returning a list makes client joining of the lines much easier.
	 * @throws RuntimeException
	 * @throws JsonException
	 */
	private static function compute_diff(string $url, $json_data): array {
		//TODO maybe use a library for computing the diff,
		/* see http://stackoverflow.com/questions/321294/ or https://github.com/chrisboulton/php-diff
		or https://github.com/sebastianbergmann/diff
		*/
		$con = UrlHelper::fetch($url);
		if(!$con) throw new RuntimeException("Couldn't fetch '$url': " . UrlHelper::$fetch_last_error);

		list($feed_data, $config_data) = self::hook1($con, $url, $json_data);

		$rss = new FeedParser($feed_data);
		$rss->init();

		if($rss->error()) throw new RuntimeException("XML errors: {$rss->error()}");

		self::hook2($rss, $config_data);

		$ref = new ReflectionClass(FeedParser::class);  # TODO use variable
		$p_doc = $ref->getProperty('doc');
		$p_doc->setAccessible(true);

		/** @var DOMDocument */
		$doc = $p_doc->getValue($rss);
		$new_feed_data = $doc->saveXML();

		if(! $new_feed_data) throw new RuntimeException("Error computing diff: modified feed is empty");

		$old_file = self::format_save_tmp($con);
		$new_file = self::format_save_tmp($new_feed_data);
		$diff = [];
		$res = 2;
		exec(Config::get('DIFF_CMD') . " $old_file $new_file", $diff, $res);
		unlink($old_file);
		unlink($new_file);

		if($res <= 1) return $diff;
		else throw new RuntimeException("Error computing diff: Status $res");
	}
}
