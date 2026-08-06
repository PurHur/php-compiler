--TEST--
xml XML_OPTION_PARSE_HUGE — forward PHP 8.4 profile set/get + mid-parse Error (#28171)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'HUGE=', defined('XML_OPTION_PARSE_HUGE') ? (string) constant('XML_OPTION_PARSE_HUGE') : 'UNDEF', "\n";
$p = xml_parser_create();
echo 'default=';
var_export(xml_parser_get_option($p, XML_OPTION_PARSE_HUGE));
echo "\n";
var_export(xml_parser_set_option($p, XML_OPTION_PARSE_HUGE, true));
echo "\n";
var_export(xml_parser_get_option($p, XML_OPTION_PARSE_HUGE));
echo "\n";
xml_parser_set_option($p, XML_OPTION_PARSE_HUGE, false);
echo 'cleared=';
var_export(xml_parser_get_option($p, XML_OPTION_PARSE_HUGE));
echo "\n";

xml_set_element_handler($p, function ($parser, $name, $attrs) {
    try {
        xml_parser_set_option($parser, XML_OPTION_PARSE_HUGE, true);
        echo "mid=ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}, function ($parser, $name) {});
xml_parse($p, '<r/>', true);
?>
--EXPECT--
HUGE=5
default=false
true
true
cleared=false
Error:Cannot change option XML_OPTION_PARSE_HUGE while parsing
