--TEST--
xml_parser_set_option() null $value — E_WARNING string|int|bool, still true (#30652, ext/xml/xml.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    echo "ERR[{$no}]:{$msg}\n";
    return true;
});
$p = xml_parser_create();
var_export(xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, null));
echo "\n";
var_export(xml_parser_get_option($p, XML_OPTION_CASE_FOLDING));
echo "\n";
var_export(xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, true));
echo "\n";
var_export(xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0));
echo "\n";
var_export(xml_parser_set_option($p, XML_OPTION_SKIP_WHITE, false));
echo "\n";
xml_parser_free($p);
?>
--EXPECT--
ERR[2]:xml_parser_set_option(): Argument #3 ($value) must be of type string|int|bool, null given
true
0
true
true
true
