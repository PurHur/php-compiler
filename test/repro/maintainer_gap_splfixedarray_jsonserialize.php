<?php

declare(strict_types=1);

// Repro for #13087 — SplFixedArray::jsonSerialize() (ext/spl/spl_fixedarray.c).

$fa = SplFixedArray::fromArray([1, 2, 3]);
if (!method_exists($fa, 'jsonSerialize')) {
    echo 'fail: jsonSerialize() missing', PHP_EOL;
    exit(1);
}
$serialized = $fa->jsonSerialize();
if ([1, 2, 3] !== $serialized) {
    echo 'fail: jsonSerialize expected [1,2,3], got ', var_export($serialized, true), PHP_EOL;
    exit(1);
}
$encoded = json_encode($fa);
if ('[1,2,3]' !== $encoded) {
    echo 'fail: json_encode expected [1,2,3], got ', var_export($encoded, true), PHP_EOL;
    exit(1);
}

$fa2 = new SplFixedArray(3);
$fa2[0] = 1;
$fa2[2] = 3;
if ('[1,null,3]' !== json_encode($fa2)) {
    echo 'fail: json_encode with null hole expected [1,null,3], got ', var_export(json_encode($fa2), true), PHP_EOL;
    exit(1);
}

echo 'ok', PHP_EOL;
