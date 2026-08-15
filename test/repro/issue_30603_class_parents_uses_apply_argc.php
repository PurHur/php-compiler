<?php
/**
 * class_parents/class_uses/iterator_apply excess/missing argc → ArgumentCountError (#30603).
 * php-src: ext/standard/spl_functions.c / ext/spl/php_spl.c
 */
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
        echo $name, ":OK\n";
    } catch (ArgumentCountError $e) {
        echo $name, ':ArgumentCountError:', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}

$parents = class_parents(new class extends stdClass {});
echo 'ok_parents:', (is_array($parents) && isset($parents['stdClass'])) ? '1' : '0', "\n";
$uses = class_uses(stdClass::class);
echo 'ok_uses:', is_array($uses) ? '1' : '0', "\n";
$n = iterator_apply(new ArrayIterator([1, 2]), static function () {
    return true;
});
echo 'ok_apply:', (2 === $n) ? '1' : '0', "\n";
