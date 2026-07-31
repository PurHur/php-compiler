<?php

declare(strict_types=1);

// After xml_parse, Expat column is line-relative and byte index matches Zend (#25817 / re-#25286).

$parser = xml_parser_create();
$beforeLine = xml_get_current_line_number($parser);
$beforeCol = xml_get_current_column_number($parser);
$beforeByte = xml_get_current_byte_index($parser);
if (1 !== $beforeLine || 1 !== $beforeCol || 0 !== $beforeByte) {
    fwrite(STDERR, "fail pre-parse: expected 1/1/0, got {$beforeLine}/{$beforeCol}/{$beforeByte}\n");
    exit(1);
}

xml_parse($parser, '<r>', true);
$line = xml_get_current_line_number($parser);
$col = xml_get_current_column_number($parser);
$byte = xml_get_current_byte_index($parser);
echo "after_r line={$line} col={$col} byte={$byte}\n";
if (1 !== $line || 1 !== $col || 0 !== $byte) {
    fwrite(STDERR, "fail after <r>: expected line=1 col=1 byte=0, got line={$line} col={$col} byte={$byte}\n");
    exit(1);
}
xml_parser_free($parser);

$parser = xml_parser_create();
xml_parse($parser, "<root>\n<a/>\n</root>", true);
$line = xml_get_current_line_number($parser);
$col = xml_get_current_column_number($parser);
$byte = xml_get_current_byte_index($parser);
echo "multi line={$line} col={$col} byte={$byte}\n";
if (3 !== $line || 8 !== $col || 19 !== $byte) {
    fwrite(STDERR, "fail multi: expected line=3 col=8 byte=19, got line={$line} col={$col} byte={$byte}\n");
    exit(1);
}
xml_parser_free($parser);

echo "ok\n";
