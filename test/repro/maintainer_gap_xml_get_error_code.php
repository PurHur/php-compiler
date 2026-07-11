<?php

$parser = xml_parser_create();
if (!function_exists('xml_get_error_code')) {
    echo "fail: xml_get_error_code() undefined\n";
    exit(1);
}
$ok = xml_parse($parser, '<root/>', true);
if (!$ok) {
    echo "fail: xml_parse() returned false for self-closing root\n";
    exit(1);
}
$code = xml_get_error_code($parser);
if (0 !== $code) {
    echo "fail: expected error code 0 after successful parse, got {$code}\n";
    exit(1);
}
echo "ok\n";
