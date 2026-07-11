<?php

declare(strict_types=1);

$obj = unserialize('O:1:"X":0:{}');
$roundTrip = serialize($obj);
if ('O:1:"X":0:{}' !== $roundTrip) {
    echo 'fail: missing class round-trip=', $roundTrip, "\n";
    exit(1);
}

class Secret
{
    public int $secret = 42;
}

$blob = serialize(new Secret());
$incomplete = unserialize($blob, ['allowed_classes' => false]);
$expected = 'O:6:"Secret":1:{s:6:"secret";i:42;}';
$actual = serialize($incomplete);
if ($expected !== $actual) {
    echo 'fail: allowed_classes round-trip=', $actual, "\n";
    exit(1);
}

echo "ok\n";
