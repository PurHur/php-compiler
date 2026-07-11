<?php

$parser = xml_parser_create();
$ok = xml_parse($parser, '<root><item/></root>', true);
if (!$ok) {
    echo "fail: xml_parse() returned false for nested self-closing child\n";
    exit(1);
}
xml_parser_free($parser);
echo "ok\n";
