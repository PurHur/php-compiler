<?php

declare(strict_types=1);

$vals = [];
$idx = [];
$status = xml_parse_into_struct(xml_parser_create(), '<a><b/></a>', $vals, $idx);
if (1 !== $status) {
    fwrite(STDERR, "fail: expected status 1, got {$status}\n");
    exit(1);
}
if (!array_key_exists('B', $idx)) {
    fwrite(STDERR, "fail: index missing key B\n");
    exit(1);
}
if (3 !== count($vals)) {
    fwrite(STDERR, 'fail: expected 3 value entries, got '.count($vals)."\n");
    exit(1);
}
if ('A' !== $vals[0]['tag'] || 'open' !== $vals[0]['type']) {
    fwrite(STDERR, "fail: first entry should be A open\n");
    exit(1);
}
if ('B' !== $vals[1]['tag'] || 'complete' !== $vals[1]['type']) {
    fwrite(STDERR, "fail: second entry should be B complete\n");
    exit(1);
}
if ('A' !== $vals[2]['tag'] || 'close' !== $vals[2]['type']) {
    fwrite(STDERR, "fail: third entry should be A close\n");
    exit(1);
}

echo "ok\n";
