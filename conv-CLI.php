<?php

include 'converter.php';

//Paste your json below (nowdoc)
$json = <<<'EOT'

EOT;

echo convert_format($json, false);
echo "\n";

?>
