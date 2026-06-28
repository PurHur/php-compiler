<?php

declare(strict_types=1);

// Repro for #13077 — SplFixedArray::getIterator() foreach (ext/spl/spl_fixedarray.c).
$a = SplFixedArray::fromArray([1, 2]);
if (!method_exists($a, 'getIterator')) {
    echo 'fail: getIterator missing', PHP_EOL;
    exit(1);
}
$out = '';
foreach ($a as $k => $v) {
    $out .= $k.'='.$v.' ';
}
if ('0=1 1=2 ' !== $out) {
    echo 'fail: foreach expected "0=1 1=2 ", got ', var_export($out, true), PHP_EOL;
    exit(1);
}
$copy = iterator_to_array($a->getIterator());
if ([0 => 1, 1 => 2] !== $copy) {
    echo 'fail: iterator_to_array mismatch: ', var_export($copy, true), PHP_EOL;
    exit(1);
}
echo 'ok', PHP_EOL;
