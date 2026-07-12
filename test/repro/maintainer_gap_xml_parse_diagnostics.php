<?php

declare(strict_types=1);

foreach ([
    'xml_error_string',
    'xml_get_current_line_number',
    'xml_get_current_column_number',
    'xml_get_current_byte_index',
] as $fn) {
    if (!function_exists($fn)) {
        echo "fail: {$fn}() undefined\n";
        exit(1);
    }
}

$parser = xml_parser_create();
$ok = xml_parse($parser, '<a><b></a>', true);
if ($ok) {
    echo "fail: xml_parse() should return false for mismatched tags\n";
    exit(1);
}

$code = xml_get_error_code($parser);
$str = xml_error_string($code);
$line = xml_get_current_line_number($parser);
$col = xml_get_current_column_number($parser);
$byte = xml_get_current_byte_index($parser);

echo "code={$code}\n";
echo "str={$str}\n";
echo "line={$line}\n";
echo "col={$col}\n";
echo "byte={$byte}\n";

if (76 !== $code || 'Mismatched tag' !== $str || 1 !== $line || 11 !== $col || 10 !== $byte) {
    echo "fail: diagnostics mismatch\n";
    exit(1);
}

$parser2 = xml_parser_create();
$ok2 = xml_parse($parser2, '<root/>', true);
if (!$ok2 || 0 !== xml_get_error_code($parser2)) {
    echo "fail: clean parse should succeed with code 0\n";
    exit(1);
}

echo "ok\n";
