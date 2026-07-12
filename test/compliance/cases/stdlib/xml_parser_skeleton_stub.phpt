--TEST--
stdlib xml_parser_create returns XMLParser object (issue #7406, #18163)
--FILE--
<?php
echo 'function_exists=', var_export(function_exists('xml_parser_create'), true), "\n";
echo 'extension_loaded=', var_export(extension_loaded('xml'), true), "\n";
$p = xml_parser_create();
echo is_object($p) ? get_class($p) : gettype($p), "\n";
?>
--EXPECT--
function_exists=true
extension_loaded=true
XMLParser
