<?php

include 'converter.php';

class ff_FeedCleaner extends Plugin
{
	private $host;
	private $debug;
	private $CONVERT = false;

	function convert_config($host)
	{
		if($this->CONVERT === true)
		{
			$json_conf = $host->get($this, 'json_conf');
			$json_conf = convert_format($json_conf);
			if (!is_null(json_decode($json_conf))) {
				$host->set($this, 'json_conf', $json_conf);
			}
			else
				user_error("Couldn't convert the configuration", E_USER_ERROR);
		}
	}

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
		/*
		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		*/
		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
	}
	

	//implement fetch hooks
	function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed_id)
	{
		$this->convert_config($this->host);
		$json_conf = $this->host->get($this, 'json_conf');
		
		$data = json_decode($json_conf, true);
		$debug_conf = sql_bool_to_bool($this->host->get($this, 'debug', bool_to_sql_bool(FALSE)));
		$this->debug = $debug_conf || $this->host->get_debug();

		$auto_correct = sql_bool_to_bool($this->host->get($this, 'auto_correct', bool_to_sql_bool(FALSE)));
		if($auto_correct)
			$feed_data = $this->auto_correct($feed_data, $fetch_url);
		
		if (!is_array($data)) {
			user_error('No or malformed configuration stored', E_USER_WARNING);
			return $feed_data;
		}
		
		foreach($data as $index => $config) {
			$test = false;
			
			//Legacy reminder
			if(!is_numeric($index) && !array_key_exists('URL_re', $config) && !array_key_exists('URL', $config))
				user_error('Please convert your configuration. Check https://github.com/wltb/ff_feedcleaner/blob/master/conf_conv.md for details.', E_USER_WARNING);
				
			if(array_key_exists('URL', $config))
				$test = (strpos($fetch_url, $config['URL']) !== false);
			elseif(array_key_exists('URL_re', $config))
				$test = (preg_match($config['URL_re'], $fetch_url) === 1);
			else
				user_error('For ' . json_encode($config) . ': Neither URL nor URL_re key is present', E_USER_WARNING);
			
			if( $test ){
				if($this->debug)
					user_error('Modifying ' . $fetch_url . ' with ' . json_encode($config), E_USER_NOTICE);
				switch (strtolower($config["type"])) {
					case "regex":
						$feed_data = $this->apply_regex($feed_data, $config);
						break;
					case "xpath_regex":
						$feed_data = $this->apply_xpath_regex($feed_data, $config);
						break;
					case "utf-8":
						$feed_data = $this->enc_utf8($feed_data, $config);
						break;
					default:
						continue;
				}
			}
		}
		
		return $feed_data;
	}

	/*
	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed_id){
		return false;
	}
	*/

	//helper functions
	function auto_correct($feed_data, $fetch_url) {
		$modified = false;

		libxml_use_internal_errors(true);
		libxml_clear_errors();
		$doc = new DOMDocument();
		@$doc->loadXML($feed_data);
		$error = libxml_get_last_error();

		// libxml compiled without iconv?
		if ($error && $error->code == 32) {
			if($this->debug)
				user_error("Trying to convert encoding of feed '$fetch_url' to UTF-8", E_USER_NOTICE);
			$feed_data = $this->enc_utf8($feed_data, array('URL' => $fetch_url, 'type' => 'auto-correct'));

			$modified = true;
			
			libxml_clear_errors();
			$doc = new DOMDocument();
			@$doc->loadXML($feed_data);

			$error = libxml_get_last_error();
		}
		
		if($error) {
			foreach(libxml_get_errors() as $err) {
				if ($err && $err->code == 9) {
					if($this->debug)
						user_error("Trying to convert encoding of feed '$fetch_url' to UTF-8", E_USER_NOTICE);
					$data = $this->enc_utf8($feed_data, array('URL' => $fetch_url, 'type' => 'auto-correct'));
					
					mb_substitute_character("none");
					$data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');

					$data = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $data, -1, $count);

					if($data) {
						$modified = true;
						$feed_data = $data;
						
						if($this->debug)
							user_error("Replaced $count invalid character(s) for '$fetch_url'", E_USER_NOTICE);
						libxml_clear_errors();

						$doc = new DOMDocument();
						@$doc->loadXML($feed_data);

						$error = libxml_get_last_error();
					}
					else
						user_error("Feed '$fetch_url': Couldn't clean faulty unicode entity", E_USER_WARNING);
					break;
				}
			}	
		}
		
		if($modified && $this->debug)
			user_error("Tried to auto correct feed '$fetch_url'", E_USER_NOTICE);

		if($error && $this->debug) {
			foreach(libxml_get_errors() as $error) {
				if($error->level == LIBXML_ERR_FATAL) {
					user_error(sprintf("For feed '$fetch_url': LibXML error %s at line %d (column %d): %s",
						$error->code, $error->line, $error->column, $error->message),
					E_USER_WARNING);
				}
			}
		}
		
		return $feed_data;
	}
	
	function enc_utf8($feed_data, $config) {
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
				if($this->debug)
					user_error('Encoding conversion to UTF-8 was successful', E_USER_NOTICE);
			}
			else
				user_error('For ' . json_encode($config) . ": Couldn't convert the encoding", E_USER_WARNING);
		}
		else {
			if($this->debug)
				user_error('No encoding declared or encoding is UTF-8 already', E_USER_NOTICE);
		}
	
		return $feed_data;
	}
	
	function apply_regex($feed_data, $config)
	{
		$pat = $config["pattern"];
		$rep = $config["replacement"];
		
		$feed_data_mod = preg_replace($pat, $rep, $feed_data, -1, $count);
		
		if($feed_data_mod !== NULL) {
			$feed_data = $feed_data_mod;
			if($this->debug)
				user_error('Applied (pattern "' . $pat . '", replacement "' . $rep . '") ' . $count . ' times', E_USER_NOTICE);
		}
	
		return $feed_data;
	}
	
	function apply_xpath_regex($feed_data, $config)
	{
		$doc = new DOMDocument();
		$doc->loadXML($feed_data);
	
		$xpath = new DOMXPath($doc);
		$node_list = $xpath->query($config['xpath']);
		
		$pat = $config["pattern"];
		$rep = $config["replacement"];
		
		if($this->debug)
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
			if($node->childNodes)
				foreach($node->childNodes as $child) {
					$preg_rep_func($child);
				}
		}
		
		if($this->debug)
			user_error('Applied (pattern "' . $pat . '", replacement "' . $rep . '") ' . $counter . ' times', E_USER_NOTICE);
		
		return $doc->saveXML();
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
		$this->convert_config($pluginhost);
		
		$json_conf = $pluginhost->get($this, 'json_conf');
		
		$debug = sql_bool_to_bool($this->host->get($this, "debug", bool_to_sql_bool(FALSE)));
		if ($debug) {
			$debugChecked = "checked=\"1\"";
		} else {
			$debugChecked = "";
		}
		$auto_correct = sql_bool_to_bool($this->host->get($this, "auto_correct", bool_to_sql_bool(FALSE)));
		if ($auto_correct) {
			$auto_correctChecked = "checked=\"1\"";
		} else {
			$auto_correctChecked = "";
		}
		
		print '<form dojoType="dijit.form.Form" accept-charset="UTF-8" style="overflow:auto;">';

		print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
			evt.preventDefault();
			if (this.validate()) {
			new Ajax.Request('backend.php', {
					parameters: dojo.objectToQuery(this.getValues()),
					onComplete: function(transport) {
						if (transport.responseText.indexOf('error')>=0) notify_error(transport.responseText);
						else notify_info(transport.responseText);
					}
				});
				//this.reset();
			}
			</script>";

		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"op\" value=\"pluginhandler\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"method\" value=\"save\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"plugin\" value=\"" . strtolower(get_class()) . "\">";

		print "<table width='100%'><tr><td>";
		print "<textarea dojoType=\"dijit.form.SimpleTextarea\" name=\"json_conf\" style=\"font-size: 12px; width: 99%; height: 500px;\">" . htmlspecialchars($json_conf, ENT_NOQUOTES, 'UTF-8') . "</textarea>";
		print "</td></tr></table>";
		
		print "<table width='30%' style=\"border:3px ridge grey;\">";
		print "<tr><td width=\"95%\"><label for=\"debug_id\">".__("Enable extended logging")."</label></td>";
		print "<td class=\"prefValue\"><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" name=\"debug\" id=\"debug_id\" $debugChecked></td></tr>";
		print "<tr><td width=\"95%\"><label for=\"auto_correct_id\">".__("Enable automatic correction")."</label></td>";
		print "<td class=\"prefValue\"><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" name=\"auto_correct\" id=\"auto_correct_id\" $auto_correctChecked></td></tr>";
		print "</table>";

		print "<p><button dojoType=\"dijit.form.Button\" type=\"submit\">".__("Save")."</button>";

		print "</form>";
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
		$this->host->set($this, 'auto_correct', checkbox_to_sql_bool($_POST["auto_correct"]));
		
		echo __("Configuration saved.");
	}

}
?>
