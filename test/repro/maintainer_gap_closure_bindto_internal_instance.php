<?php

declare(strict_types=1);

$fail = static function (string $msg): never {
    fwrite(STDERR, "fail: {$msg}\n");
    exit(1);
};

$c = function (): int {
    return 42;
};

$std = $c->bindTo(new stdClass());
if (!$std instanceof Closure) {
    $fail('bindTo(new stdClass()) expected Closure, got ' . get_debug_type($std));
}

$ao = $c->bindTo(new ArrayObject());
if (!$ao instanceof Closure) {
    $fail('bindTo(new ArrayObject()) expected Closure, got ' . get_debug_type($ao));
}

if ($std() !== 42) {
    $fail('bound closure invoke expected 42');
}

echo "ok\n";
