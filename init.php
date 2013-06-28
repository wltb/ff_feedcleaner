<?php

include 'converter.php';

function apply_regex($feed_data, $config, $debug = false)
{
	$pat = $config["pattern"];
	$rep = $config["replacement"];
	
	$feed_data_mod = preg_replace($pat, $rep, $feed_data, -1, $count);
	
	if($debug)
		user_error('Applied (pattern "' . $pat . '", replacement "' . $rep . '") ' . $count . ' times', E_USER_NOTICE);
	
	if($feed_data_mod)
		$feed_data = $feed_data_mod;
	
	return $feed_data;
}

function apply_xpath_regex($feed_data, $config, $debug = false)
{
	$doc = new DOMDocument();
	$doc->loadXML($feed_data);
	
	$xpath = new DOMXPath($doc);
	$node_list = $xpath->query($config['xpath']);
	
	$pat = $config["pattern"];
	$rep = $config["replacement"];
	
	if($debug)
		user_error('Found ' . $node_list->length . ' nodes with XPath "' . $config['xpath'] . '"', E_USER_NOTICE);
		
	$counter = 0;
	foreach($node_list as $node) {
		if( $node->childNodes->length != 1) {
			user_error('Node "' . $node->getNodePath() . '" has more then one (or no) child. Modifying the text content of such nodes is not supported at the moment', E_USER_WARNING);
			continue;
		}
		foreach($node->childNodes as $child) {
			if($child->nodeType == XML_TEXT_NODE) {
				$text_mod = preg_replace($pat, $rep, $child->textContent, -1, $count);
				if($text_mod)
					$child->nodeValue = $text_mod;
			}
		}
		$counter += $count;
	}
	
	if($debug)
		user_error('Applied (pattern "' . $pat . '", replacement "' . $rep . '") ' . $counter . ' times', E_USER_NOTICE);
	
	return $doc->saveXML();
}

class ff_FeedCleaner extends Plugin
{
	private $host;
	private $debug = false;
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
			0.8, // version
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
			user_error('Hooks not registered. Needs at least version 1.8', E_USER_ERROR);
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

		if (!is_array($data)) {
			user_error('No or malformed configuration stored', E_USER_ERROR);
			return $feed_data;
		}
		
		foreach($data as $index => $config) {
			$test = false;
			
			//Legacy support
			if(!is_numeric($index) && !array_key_exists('URL_re', $config))
				$config['URL_re'] = $index;
				
			if(array_key_exists('URL', $config))
				$test = (strpos($fetch_url, $config['URL']) !== false);
			else
				$test = (preg_match($config['URL_re'], $fetch_url) === 1);
			
			if( $test ){
				if($this->debug || $this->host->get_debug())
					user_error('Modifying ' . $fetch_url . ' with ' . json_encode($config), E_USER_NOTICE);
				switch (strtolower($config["type"])) {
					case "regex":
						$feed_data = apply_regex($feed_data, $config, $this->debug || $this->host->get_debug());
						break;
					case "xpath_regex":
						$feed_data = apply_xpath_regex($feed_data, $config, $this->debug || $this->host->get_debug());
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

		print '<form dojoType="dijit.form.Form" accept-charset="UTF-8">';

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
		echo __("Configuration saved.");
	}

}
?>
