<?php
error_reporting(E_ALL);
$p = xml_parser_create();
try {
    var_export(xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, null));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage();
}
echo "\n";
xml_parser_free($p);
