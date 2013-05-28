<?php

class ff_FeedCleaner extends Plugin implements IHandler
{
	private $host;

	function about()
	{
		return array(
			0.4, // version
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

		if (version_compare(VERSION_STATIC, '1.7.10', '<') && VERSION_STATIC === VERSION){
			user_error('Hooks not registered. Needs trunk or version >= 1.7.10', E_USER_WARNING);
			return;
		}
		
		$host->add_hook($host::HOOK_PREFS_TABS, $this);
# only allowed for system plugins: $host->add_handler('pref-feedmod', '*', $this);
		/*
		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		*/
		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
	}
	

	//implement fetch hooks
	function hook_feed_fetched($feed_data, $fetch_url, $owner_uid)
	{

		$json_conf = $this->host->get($this, 'json_conf');
		$data = json_decode($json_conf, true);

		if (!is_array($data)) {
			user_error('No or malformed configuration stored', E_USER_WARNING);
			return $feed_data;
		}
		
		foreach($data as $url_match => $config) {
			if(preg_match($url_match, $fetch_url) === 1 ){
				switch ($config["type"]) {
					case "regex":
						$rep = preg_replace($config["pattern"], $config["replacement"], $feed_data);
						if($rep)
							$feed_data = $rep;
						break;
					default:
						continue;
				}
			}
		}
		return $feed_data;

	}

	/*
	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid){
		return false;
	}
	*/

	
	//IHandler implementation - for GUI
	function csrf_ignore($method)
	{
		$csrf_ignored = array("index", "edit");
		return array_search($method, $csrf_ignored) !== false;
	}

	function before($method)
	{
		if ($_SESSION["uid"]) {
			return true;
		}
		return false;
	}

	function after()
	{
		return true;
	}
	
	//gui hook stuff
	function hook_prefs_tabs($args)
	{
		print '<div id="' . strtolower(get_class()) . '_ConfigTab" dojoType="dijit.layout.ContentPane"
			href="backend.php?op=' . strtolower(get_class()) . '"
			title="' . __('FeedCleaner') . '"></div>';
	}

	function index()
	{
		$pluginhost = PluginHost::getInstance();
		$json_conf = $pluginhost->get($this, 'json_conf');

		print "<form dojoType=\"dijit.form.Form\">";

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
