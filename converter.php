<?php

//Taken from Kendall Hopkins (http://stackoverflow.com/a/9776726)
function prettyPrint( $json )
{
	$result = '';
	$level = 0;
	$prev_char = '';
	$in_quotes = false;
	$ends_line_level = NULL;
	$json_length = strlen( $json );

	for( $i = 0; $i < $json_length; $i++ ) {
		$char = $json[$i];
		$new_line_level = NULL;
		$post = "";
		if( $ends_line_level !== NULL ) {
			$new_line_level = $ends_line_level;
			$ends_line_level = NULL;
		}
		if( $char === '"' && $prev_char != '\\' ) {
			$in_quotes = !$in_quotes;
		} else if( ! $in_quotes ) {
			switch( $char ) {
				case '}': case ']':
					$level--;
					$ends_line_level = NULL;
					$new_line_level = $level;
					break;

				case '{': case '[':
					$level++;
				case ',':
					$ends_line_level = $level;
					break;

				case ':':
					$post = " ";
					break;

				case " ": case "\t": case "\n": case "\r":
					$char = "";
					$ends_line_level = $new_line_level;
					$new_line_level = NULL;
					break;
			}
		}
		if( $new_line_level !== NULL ) {
			$result .= "\n".str_repeat( "\t", $new_line_level );
		}
		$result .= $char.$post;
		$prev_char = $char;
	}

	return $result;
}

function convert_format($json, $debug = true) {

	if($debug)
		user_error('Converting ' . $json, E_USER_NOTICE);

	$data = json_decode($json, true);
	$json = array();
	
	$url_key = 'URL_re';
	
	foreach($data as $url => $config) {
		if(!array_key_exists('URL', $config) && !array_key_exists($url_key, $config) && !is_numeric($url))
			$config = array($url_key => $url) + $config;
		array_push($json, $config);
	}
	
	return prettyPrint( str_replace('\/', '/', json_encode($json)));
}

?>
