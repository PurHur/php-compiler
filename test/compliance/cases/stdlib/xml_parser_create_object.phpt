--TEST--
stdlib xml_parser_create() returns XMLParser object (#18163, ext/xml/xml.c)
--FILE--
<?php
$p = xml_parser_create();
echo is_object($p) ? get_class($p) : gettype($p), "\n";
$ok = xml_parse($p, '<root/>', true);
echo 'parse=', var_export($ok, true), "\n";
echo 'free=', var_export(xml_parser_free($p), true), "\n";
try {
    new XMLParser();
    echo "direct_construct_ok\n";
} catch (Error $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
XMLParser
parse=1
free=true
Error
