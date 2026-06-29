<?php
declare(strict_types=1);
// Repro for #13400 — array reference cycles in gc_collect_cycles()

$fail = static function (string $label, $got, $expected): void {
    echo "fail {$label} collected {$got} expected {$expected}\n";
    exit(1);
};

$a = [];
$a[0] = &$a;
unset($a);
$got = gc_collect_cycles();
if (1 !== $got) {
    $fail('array self-ref', $got, 1);
}
echo "ok array self-ref collected {$got}\n";

$a = [];
$b = [];
$a[0] = &$b;
$b[0] = &$a;
unset($a, $b);
$got = gc_collect_cycles();
if (2 !== $got) {
    $fail('two-array cycle', $got, 2);
}
echo "ok two-array cycle collected {$got}\n";

$o = new stdClass();
$a = [&$o];
$o->self = $a;
unset($o, $a);
$got = gc_collect_cycles();
if (2 !== $got) {
    $fail('mixed object/array cycle', $got, 2);
}
echo "ok mixed object/array cycle collected {$got}\n";

echo "ok\n";
