<?php

declare(strict_types=1);

/**
 * Issue #18203 repro — xml_set_element_handler() + xml_parser_set_option().
 */
function xml_sax_elem_start($parser, $name, $attrs): void
{
    echo "start:{$name}\n";
}

function xml_sax_elem_end($parser, $name): void
{
    echo "end:{$name}\n";
}

$p = xml_parser_create();
if (!xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0)) {
    fwrite(STDERR, "fail: xml_parser_set_option CASE_FOLDING\n");
    exit(1);
}
if (0 !== xml_parser_get_option($p, XML_OPTION_CASE_FOLDING)) {
    fwrite(STDERR, "fail: case folding should be 0\n");
    exit(1);
}
if (!xml_set_element_handler($p, 'xml_sax_elem_start', 'xml_sax_elem_end')) {
    fwrite(STDERR, "fail: xml_set_element_handler\n");
    exit(1);
}
if (1 !== xml_parse($p, '<root><a/></root>', true)) {
    fwrite(STDERR, "fail: xml_parse returned 0\n");
    exit(1);
}
xml_parser_free($p);
echo "done\n";
