<?php

$parser = xml_parser_create();
if (!function_exists('xml_parser_free')) {
    echo "fail: xml_parser_free() undefined after xml_parser_create()\n";
    exit(1);
}
$ok = xml_parse($parser, '<root/>', true);
if (!$ok) {
    echo "fail: xml_parse() returned false for self-closing root\n";
    exit(1);
}
if (!xml_parser_free($parser)) {
    echo "fail: xml_parser_free() returned false\n";
    exit(1);
}
echo "ok\n";
