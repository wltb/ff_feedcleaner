<?php

class ff_FeedCleaner extends Plugin
{
	private $host;
	private $debug;

	function about()
	{
		return array(
			0.9, // version
			'Applies regular expressions to a feed', // description
			'feader', // author
			false, // is_system
		);
	}

	function api_version()
	{
		return 2;
	}

	function init($host)
	{
		$this->host = $host;

		if (version_compare(VERSION_STATIC, '1.8', '<')){
			user_error('Hooks not registered. Needs at least version 1.8', E_USER_WARNING);
			return;
		}

		$host->add_hook($host::HOOK_PREFS_TABS, $this);
		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
		$host->add_hook($host::HOOK_FEED_PARSED, $this);
	}

	//implement fetch hooks
	function hook_feed_fetched($feed_data, $fetch_url)
	{
		$json_conf = $this->host->get($this, 'json_conf');
		$debug_conf = sql_bool_to_bool($this->host->get($this, 'debug', bool_to_sql_bool(FALSE)));
		$this->debug = $debug_conf; // || $this->host->get_debug();

		try {
			list($feed_data, $this->feed_parsed) = self::hook1($feed_data, $fetch_url, $json_conf, $this->debug);
		} catch (Exception $e) {
			user_error($e->getMessage(), E_USER_WARNING);
		}

		return $feed_data;
	}

	static function hook1($feed_data, $fetch_url, $json_conf, $debug=False) {
		$data = json_decode($json_conf, true);
		if (!$data || !is_array($data)) throw new Exception('No or malformed configuration stored');

		$later = array();
		foreach($data as $config) {
			$test = false;

			if(array_key_exists('URL', $config))
				$test = (strpos($fetch_url, $config['URL']) !== false);
			elseif(array_key_exists('URL_re', $config))
				$test = (preg_match($config['URL_re'], $fetch_url) === 1);
			else
				user_error('For ' . json_encode($config) . ': Neither URL nor URL_re key is present', E_USER_WARNING);

			if( $test ){
				if($debug)
					user_error('Modifying ' . $fetch_url . ' with ' . json_encode($config), E_USER_NOTICE);
				switch (strtolower($config["type"])) {
					case "regex":
						$feed_data = self::apply_regex($feed_data, $config, $debug);
						break;
					case "xpath_regex":
						$later [] = $config;
						break;
					case "utf-8":
						$feed_data = self::enc_utf8($feed_data, $config, $debug);
						break;
					default:
						continue;
				}
			}
		}

		return array($feed_data, $later);
	}


	function hook_feed_parsed($rss) {
		if(!$this->feed_parsed)
			return;
		self::hook2($rss, $this->feed_parsed, $this->debug);
	}

	static function hook2($rss, $config_data, $debug=False) {
		static $p_xpath;
		if(!$p_xpath) { #initialize reflection
			$ref = new ReflectionClass('FeedParser');
			$p_xpath = $ref->getProperty('xpath');
			$p_xpath->setAccessible(true);
		}
		$xpath = $p_xpath->getValue($rss);
		//$xpath->registerNamespace('rssfake', "http://purl.org/rss/1.0/");

		foreach($config_data as $config) {
			//var_dump($config);
			switch (strtolower($config["type"])) {
			case "xpath_regex":
				self::apply_xpath_regex($xpath, $config, $debug);
				break;
			}
		}
	}

	static function enc_utf8($feed_data, $config, $debug) {
		$decl_regex =
			'/^(<\?xml
				[\t\n\r ]+version[\t\n\r ]*=[\t\n\r ]*["\']1\.[0-9]+["\']
				[\t\n\r ]+encoding[\t\n\r ]*=[\t\n\r ]*["\'])([A-Za-z][A-Za-z0-9._-]*)(["\']
				(?:[\t\n\r ]+standalone[\t\n\r ]*=[\t\n\r ]*["\'](?:yes|no)["\'])?
			[\t\n\r ]*\?>)/x';
		if (preg_match($decl_regex, $feed_data, $matches) === 1 && strtoupper($matches[2]) != 'UTF-8') {
			mb_substitute_character("none");
			$data = mb_convert_encoding($feed_data, 'UTF-8', $matches[2]);
			if($data !== false)
			{
				$feed_data = preg_replace($decl_regex, $matches[1] . "UTF-8" . $matches[3], $data);
				if($debug)
					user_error('Encoding conversion to UTF-8 was successful', E_USER_NOTICE);
			}
			else
				user_error('For ' . json_encode($config) . ": Couldn't convert the encoding", E_USER_WARNING);
		}
		else {
			if($debug)
				user_error('No encoding declared or encoding is UTF-8 already', E_USER_NOTICE);
		}

		return $feed_data;
	}

	static function apply_regex($feed_data, $config, $debug=false)
	{
		$pat = $config["pattern"];
		$rep = $config["replacement"];

		$feed_data_mod = preg_replace($pat, $rep, $feed_data, -1, $count);

		if($feed_data_mod !== NULL)
			$feed_data = $feed_data_mod;
		else {
			$count = 0;
		}

		if($debug)
			user_error('Applied (pattern "' . $pat . '", replacement "' . $rep . '") ' . $count . ' times', E_USER_NOTICE);

		return $feed_data;
	}

	static function apply_xpath_regex($xpath, $config, $debug=false) {
		if(isset($config['namespaces']) && is_array($config['namespaces']))
			foreach($config['namespaces'] as $prefix => $URI)
				$xpath->registerNamespace($prefix, $URI);
		else { //TODO remove this
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

		if($debug)
			user_error('Found ' . $node_list->length . ' nodes with XPath "' . $config['xpath'] . '"', E_USER_NOTICE);

		$preg_rep_func = function($node) use ($pat, $rep, &$counter) {
			if( $node->nodeType == XML_TEXT_NODE) {
				$text_mod = preg_replace($pat, $rep, $node->textContent, -1, $count);
				if($text_mod !== NULL) {
					$node->nodeValue = $text_mod;
					$counter += $count;
				}
			}
		};
		$counter = 0;
		foreach($node_list as $node) {
			$preg_rep_func($node);
			if($node->hasChildNodes())
				// This also works for DOMAttributes because apparently,
				// their nodeValue is stored in a TextNode child.
				foreach($node->childNodes as $child)
					$preg_rep_func($child);
		}

		if($debug)
			user_error('Applied (pattern "' . $pat . '", replacement "' . $rep . '") ' . $counter . ' times', E_USER_NOTICE);
	}

	//gui hook stuff
	function hook_prefs_tabs($args)
	{
		print '<div id="' . strtolower(get_class()) . '_ConfigTab" dojoType="dijit.layout.ContentPane"
			href="backend.php?op=pluginhandler&plugin=' . strtolower(get_class()) . '&method=index"
			title="' . __('FeedCleaner') . '"></div>';
	}

	function index()
	{
		$pluginhost = PluginHost::getInstance();
		$json_conf = $pluginhost->get($this, 'json_conf');

		$debug = sql_bool_to_bool($this->host->get($this, "debug", bool_to_sql_bool(FALSE)));
		if ($debug) {
			$debugChecked = "checked=\"1\"";
		} else {
			$debugChecked = "";
		}

		?>
<div data-dojo-type="dijit/layout/AccordionContainer" style="height:100%;">
	<div data-dojo-type="dijit/layout/ContentPane" title="<?php print __('Preferences'); ?>" selected="true">
	<form dojoType="dijit.form.Form" accept-charset="UTF-8" style="overflow:auto;"
	id="feedcleaner_settings">
	<script type="dojo/method" event="onSubmit" args="evt">
		evt.preventDefault();
		if (this.validate()) {
			var values = this.getValues();
			values.op = "pluginhandler";
			values.method = "save";
			values.plugin = "<?php print strtolower(get_class());?>";
			new Ajax.Request('backend.php', {
				parameters: dojo.objectToQuery(values),
				onComplete: function(transport) {
					if (transport.responseText.indexOf('error')>=0) notify_error(transport.responseText);
					else notify_info(transport.responseText);
				}
			});
			//this.reset();
		}
	</script>
	<table width='100%'><tr><td>
		<textarea dojoType="dijit.form.SimpleTextarea" name="json_conf"
			style="font-size: 12px; width: 99%; height: 500px;"
			><?php echo htmlspecialchars($json_conf, ENT_NOQUOTES, 'UTF-8');?></textarea>
	</td></tr></table>

	<table width='30%' style="border:3px ridge grey;"><tr>
		<td width="95%">
		<label for="debug_id"><?php echo __("Enable extended logging");?></label>
		</td>
		<td class="prefValue">
			<input dojoType="dijit.form.CheckBox" type="checkbox" name="debug" id="debug_id"
				<?php print $debugChecked;?>>
		</td>
		</tr>
	</table>
	<p><button dojoType="dijit.form.Button" type="submit"><?php print __("Save");?></button></p>
	</form>
	</div>

	<div data-dojo-type="dijit/layout/ContentPane" title="<?php print __('Show Diff'); ?>">
	<form dojoType="dijit.form.Form">
		<script type="dojo/method" event="onSubmit" args="evt">
			evt.preventDefault();
			if (this.validate()) {
				var values = this.getValues();
				values.json_conf = dijit.byId("feedcleaner_settings").value.json_conf;
				values.op = "pluginhandler";
				values.method = "preview";
				values.plugin = "<?php print strtolower(get_class());?>";
				new Ajax.Request('backend.php', {
					parameters: dojo.objectToQuery(values),
					onComplete: function(transport) {
						if (transport.responseText.indexOf('error:')==0) notify_error(transport.responseText);
						else {
							var preview = document.getElementById("preview");
							preview.innerHTML = transport.responseText;//textContent
						}
					}
				});
				//this.reset();
			}
		</script>
		URL: <input dojoType="dijit.form.TextBox" name="url"> <button dojoType="dijit.form.Button" type="submit"><?php print __("Preview"); ?></button>
	</form>
	<div id="preview" style="border:2px solid grey; min-height:2cm; max-width: 30cm;"><?php print __("Preview"); ?></div>
	</div>
</div>

		<?php
	}

	function save()
	{
		$json_conf = $_POST['json_conf'];

		if (is_null(json_decode($json_conf))) {
			echo __("error: Invalid JSON!");
			return false;
		}

		$this->host->set($this, 'json_conf', $json_conf);
		$this->host->set($this, 'debug', checkbox_to_sql_bool($_POST["debug"]));

		echo __("Configuration saved.");
	}

	// diff stuff

	static function format_diff_array_html($ar) {
		// TODO should make sure that everything is indeed utf-8.
		// This may be not the case if below, the feed data is not loaded into a xml doc.
		// (and not even then?)
		$func = function($var) {return htmlspecialchars($var, ENT_QUOTES, "UTF-8");};//ENT_XML1 would be better?
		$diff = array_map($func, $ar);

		return implode("<br/>", $diff);
	}

	function preview()
	{
		$url = $_POST['url'];
		$conf = $_POST['json_conf'];

		// TODO The output should be better structured, the check with 'error:' is a bit clumsy
		try {
			$diff = self::compute_diff($url, $conf);
			print self::format_diff_array_html($diff);
		} catch (Exception $e) {
			print "error: " . $e->getMessage();
		}
	}

	static function format_save_tmp($data, $format=True) {
		if($format) {
			$xml = new DOMDocument();
			$xml->preserveWhiteSpace = false;
			$xml->formatOutput = true;
			$res = $xml->loadXML($data);
		}

		$filename = tempnam(sys_get_temp_dir(), '');
		if($format && $res) $outXML = $xml->save($filename);
		else {
			$handle = fopen($filename, "w");
			fwrite($handle, $data);
			fclose($handle);
		}

		return array($filename, $format && $res);
	}

	const diff_cmd = 'diff -U 0 -s -w ';

	static function compute_diff($url, $json_data) {
		//TODO maybe use a library for computing the diff,
		// see http://stackoverflow.com/questions/321294/ or https://github.com/chrisboulton/php-diff
		$con = fetch_file_contents($url);
		if(!$con) throw new Exception("Couldn't fetch $url");

		try {
			list($feed_data, $config_data) = self::hook1($con, $url, $json_data);
		} catch (Exception $e) {
			throw $e;
		}

		$rss = new FeedParser($feed_data);
		$rss->init();
		if(!$rss->error()) {
			self::hook2($rss, $config_data);

			$ref = new ReflectionClass('FeedParser');
			$p_doc = $ref->getProperty('doc');
			$p_doc->setAccessible(true);

			$doc = $p_doc->getValue($rss);
			$new_feed_data = $doc->saveXML();

			list($old_file, $xml) = self::format_save_tmp($con);
			list($new_file, $xml) = self::format_save_tmp($new_feed_data, $xml);
			$diff = array();
			$res = 2;
			exec(self::diff_cmd . " $old_file $new_file", $diff, $res);
			unlink($old_file);
			unlink($new_file);

			//var_dump($diff);
			if($res <= 1) {
				return $diff;
			} else throw new Exception("Error computing diff");
		} else throw new Exception("XML errors: {$rss->error()}");
	}
}
?>
