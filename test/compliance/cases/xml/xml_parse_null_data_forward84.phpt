--TEST--
xml_parse()/xml_parse_into_struct() null $data — DEP+coerce on 8.4 forward profile (#21505, ext/xml/xml.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    echo "DEP:{$msg}\n";
    return true;
});
$p = xml_parser_create();
$r1 = xml_parse($p, null);
echo "xml_parse:{$r1}\n";
xml_parser_free($p);
$p2 = xml_parser_create();
$values = [];
$index = [];
$r2 = xml_parse_into_struct($p2, null, $values, $index);
echo "xml_parse_into_struct:{$r2}\n";
xml_parser_free($p2);
?>
--EXPECT--
DEP:xml_parse(): Passing null to parameter #2 ($data) of type string is deprecated
xml_parse:1
DEP:xml_parse_into_struct(): Passing null to parameter #2 ($data) of type string is deprecated
xml_parse_into_struct:0
