<?php
/** Repro #21522 — xml_set_object + non-callable string handlers E_DEPRECATED on PROFILE=8.4. */
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
echo "done\n";
