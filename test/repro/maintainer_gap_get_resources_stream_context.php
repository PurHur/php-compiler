<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
$g = fopen('php://memory', 'r+');
if (!is_resource($f) || !is_resource($g)) {
    fwrite(STDERR, "fail: fopen php://memory\n");
    exit(1);
}

$total = count(get_resources());
$streams = count(get_resources('stream'));
if (6 !== $total) {
    fwrite(STDERR, "fail: total count expected 6 got {$total}\n");
    exit(1);
}
if (5 !== $streams) {
    fwrite(STDERR, "fail: stream count expected 5 got {$streams}\n");
    exit(1);
}

$types = [];
foreach (get_resources() as $res) {
    $types[] = get_resource_type($res);
}
if (!in_array('stream-context', $types, true)) {
    fwrite(STDERR, 'fail: missing stream-context in get_resources(): ' . json_encode($types) . "\n");
    exit(1);
}

echo "ok\n";
