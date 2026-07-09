<?php
declare(strict_types=1);

var_export('hi' instanceof stdClass);
echo "\n";

var_export((new stdClass()) instanceof stdClass);
echo "\n";

$doc = new DOMDocument();
$t = $doc->createTextNode('hello');
var_export($t instanceof DOMText);
echo "\n";

$is = $t instanceof DOMText;
var_export($is);
echo "\n";

var_export($t instanceof DOMText, true);
echo "\n";
