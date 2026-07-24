<?php
// Zend: xml_parser_free is a no-op since PHP 8.0 (XMLParser object).
// VM: frees internal handle → later xml_parse/xml_parser_free ValueError.
$p = xml_parser_create();
xml_parser_free($p);
try {
    $r = xml_parse($p, '<a/>', true);
    echo 'parse_ret=', var_export($r, true), ' type=', get_debug_type($p), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(xml_parser_free($p));
    echo "\n";
} catch (Throwable $e) {
    echo 'free2 ', get_class($e), ': ', $e->getMessage(), "\n";
}
