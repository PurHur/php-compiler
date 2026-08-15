--TEST--
stdlib JIT: class_parents/class_uses/iterator_apply ArgumentCountError wording (#30603)
--FILE--
<?php
foreach ([
    'parents_hi' => static fn () => class_parents(stdClass::class, true, 1),
    'parents_lo' => static fn () => class_parents(),
    'uses_hi' => static fn () => class_uses(stdClass::class, true, 1),
    'uses_lo' => static fn () => class_uses(),
    'apply_hi' => static fn () => iterator_apply(new ArrayIterator([]), fn () => 1, null, 1),
    'apply_lo' => static fn () => iterator_apply(new ArrayIterator([])),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$parents = class_parents(new class extends stdClass {});
echo 'ok_parents=', (is_array($parents) && isset($parents['stdClass'])) ? '1' : '0', "\n";
$uses = class_uses(stdClass::class);
echo 'ok_uses=', is_array($uses) ? '1' : '0', "\n";
$n = iterator_apply(new ArrayIterator([1, 2]), static function () {
    return true;
});
echo 'ok_apply=', (2 === $n) ? '1' : '0', "\n";
--EXPECT--
parents_hi ArgumentCountError: class_parents() expects at most 2 arguments, 3 given
parents_lo ArgumentCountError: class_parents() expects at least 1 argument, 0 given
uses_hi ArgumentCountError: class_uses() expects at most 2 arguments, 3 given
uses_lo ArgumentCountError: class_uses() expects at least 1 argument, 0 given
apply_hi ArgumentCountError: iterator_apply() expects at most 3 arguments, 4 given
apply_lo ArgumentCountError: iterator_apply() expects at least 2 arguments, 1 given
ok_parents=1
ok_uses=1
ok_apply=1
