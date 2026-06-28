<?php

$engine = new Random\Engine\Mt19937(42);
for ($i = 0; $i < 3; ++$i) {
    $engine->generate();
}

$blob = serialize($engine);
if ('' === $blob) {
    echo "fail: serialize() returned empty string\n";
    exit(1);
}

$restored = unserialize($blob);
if (!($restored instanceof Random\Engine\Mt19937)) {
    echo 'fail: unserialize() did not restore Random\\Engine\\Mt19937'."\n";
    exit(1);
}

$expected = (new Random\Randomizer($engine))->getInt(1, 100);
$actual = (new Random\Randomizer($restored))->getInt(1, 100);
if ($expected !== $actual) {
    echo "fail: Randomizer getInt mismatch {$expected} vs {$actual}\n";
    exit(1);
}

echo "ok\n";
