--TEST--
xml_parser_set_option() null $value — silent true on reference PROFILE (Zend 8.2, #30652)
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
xml_parser_free($p);
?>
--EXPECT--
true
