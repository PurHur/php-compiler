<?php
/**
 * Issue #13713 — array literal with by-ref element must retain keys and referenced values.
 */

$b = 2;
$a = [1 => &$b];

if (!array_key_exists(1, $a)) {
    fwrite(STDERR, "FAIL: array_key_exists(1, \$a) expected true\n");
    exit(1);
}

if (count($a) !== 1) {
    fwrite(STDERR, 'FAIL: count($a) expected 1, got '.count($a)."\n");
    exit(1);
}

$values = array_values($a);
if ($values !== [2]) {
    fwrite(STDERR, 'FAIL: array_values($a) expected [2], got '.var_export($values, true)."\n");
    exit(1);
}

$ser = serialize($a);
if ($ser !== 'a:1:{i:1;i:2;}') {
    fwrite(STDERR, 'FAIL: serialize($a) expected a:1:{i:1;i:2;}, got '.$ser."\n");
    exit(1);
}

$json = json_encode($a);
if ($json !== '{"1":2}') {
    fwrite(STDERR, 'FAIL: json_encode($a) expected {"1":2}, got '.$json."\n");
    exit(1);
}

$b = 99;
if ($a[1] !== 99) {
    fwrite(STDERR, 'FAIL: mutating $b should update $a[1], got '.var_export($a[1], true)."\n");
    exit(1);
}

echo "OK\n";
