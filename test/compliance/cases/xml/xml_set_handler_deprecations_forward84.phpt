--TEST--
ext/xml xml_set_object()/xml_set_*_handler() — E_DEPRECATED on PROFILE=8.4 (#21522, ext/xml/xml.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    echo "DEP:{$msg}\n";
    return true;
});
class H
{
    public function c($p, $d): void
    {
    }
}
$h = new H();
$p = xml_parser_create();
xml_set_object($p, $h);
xml_set_character_data_handler($p, 'c');
xml_set_character_data_handler($p, 'strlen');
xml_set_character_data_handler($p, function ($p, $d) {});
echo "done\n";
?>
--EXPECT--
DEP:Function xml_set_object() is deprecated since 8.4, provide a proper method callable to xml_set_*_handler() functions
DEP:xml_set_character_data_handler(): Passing non-callable strings is deprecated since 8.4
done
