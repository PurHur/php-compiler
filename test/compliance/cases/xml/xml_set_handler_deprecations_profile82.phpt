--TEST--
ext/xml xml_set_object()/handlers — no E_DEPRECATED on PROFILE=8.2 (#21522)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        ++$deps;
        echo "DEP:{$msg}\n";
    }
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
echo $deps === 0 ? "silent\n" : "leaked\n";
?>
--EXPECT--
silent
