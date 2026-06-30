<?php
declare(strict_types=1);

if (!method_exists('SplStack', 'top')) {
    echo "missing_top\n";
    exit(1);
}

$stack = new SplStack();
$stack->push('a');
$stack->push('b');
$top = $stack->top();
if ('b' !== $top) {
    echo 'bad_top='.var_export($top, true)."\n";
    exit(1);
}
if (2 !== $stack->count()) {
    echo 'count_changed='.(string) $stack->count()."\n";
    exit(1);
}

try {
    (new SplStack())->top();
    echo "empty_no_throw\n";
    exit(1);
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'empty')) {
        echo 'bad_empty='.get_class($e).': '.$e->getMessage()."\n";
        exit(1);
    }
}

echo "OK\n";
