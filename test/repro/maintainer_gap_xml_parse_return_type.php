<?php

declare(strict_types=1);

/**
 * Issue #18149 — xml_parse() failure returns int 0, success returns int 1 (php-src ext/xml/xml.c).
 */

$parser = xml_parser_create();
$fail = xml_parse($parser, '<a><b></a>', true);
if (!is_int($fail) || 0 !== $fail) {
    echo 'fail: expected int(0) on parse failure, got ', var_export($fail, true), "\n";
    exit(1);
}

$parser2 = xml_parser_create();
$ok = xml_parse($parser2, '<root/>', true);
if (!is_int($ok) || 1 !== $ok) {
    echo 'fail: expected int(1) on parse success, got ', var_export($ok, true), "\n";
    exit(1);
}

echo "ok\n";
