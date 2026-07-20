<?php
/**
 * Repro #21505 — xml_parse(null $data) must DEP+coerce to '' and return 1 (Zend php-src-strict).
 * PROFILE=8.4: soft-null, not TypeError.
 */
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        $deps[] = $msg;
    }

    return true;
});

$p = xml_parser_create();
$status = xml_parse($p, null);
xml_parser_free($p);

$p2 = xml_parser_create();
$values = [];
$index = [];
$status2 = xml_parse_into_struct($p2, null, $values, $index);
xml_parser_free($p2);

$ok = 1 === $status
    && 0 === $status2
    && isset($deps[0]) && false !== strpos($deps[0], 'xml_parse(): Passing null to parameter #2 ($data)')
    && isset($deps[1]) && false !== strpos($deps[1], 'xml_parse_into_struct(): Passing null to parameter #2 ($data)');

echo $ok ? "xml_parse_null_ok=1\n" : "xml_parse_null_ok=0\n";
echo "xml_parse={$status}\n";
echo "xml_parse_into_struct={$status2}\n";
echo 'deps='.count($deps)."\n";
exit($ok ? 0 : 1);
