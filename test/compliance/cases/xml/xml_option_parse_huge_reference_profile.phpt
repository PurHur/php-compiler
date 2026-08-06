--TEST--
xml XML_OPTION_PARSE_HUGE withheld on reference PROFILE (#28171, ext/xml/xml.stub.php)
--FILE--
<?php
echo 'HUGE=', defined('XML_OPTION_PARSE_HUGE') ? (string) constant('XML_OPTION_PARSE_HUGE') : 'UNDEF', "\n";
echo 'CASE=', defined('XML_OPTION_CASE_FOLDING') ? (string) constant('XML_OPTION_CASE_FOLDING') : 'UNDEF', "\n";
$p = xml_parser_create();
try {
    xml_parser_set_option($p, 5, 1);
    echo "set5=ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
HUGE=UNDEF
CASE=1
ValueError:xml_parser_set_option(): Argument #2 ($option) must be a XML_OPTION_* constant
