<?php

declare(strict_types=1);

// Fresh XMLParser: Expat reports line=1 col=1 byte=0 before any parse (#25286 / re-#18120).
$parser = xml_parser_create();
$line = xml_get_current_line_number($parser);
$col = xml_get_current_column_number($parser);
$byte = xml_get_current_byte_index($parser);

echo "line={$line}\n";
echo "col={$col}\n";
echo "byte={$byte}\n";

if (1 !== $line || 1 !== $col || 0 !== $byte) {
    fwrite(STDERR, "fail: expected line=1 col=1 byte=0, got line={$line} col={$col} byte={$byte}\n");
    exit(1);
}

echo "ok\n";
