<?php
$h = fopen('php://memory', 'r+');
$expected = (float) get_resource_id($h);
$actual = (float) $h;
if ($expected !== $actual) {
    fwrite(STDERR, "expected float $expected, got $actual\n");
    exit(1);
}
echo "ok\n";
