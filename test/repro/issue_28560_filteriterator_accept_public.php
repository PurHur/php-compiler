<?php

declare(strict_types=1);

/**
 * #28560 — FilterIterator::accept() and siblings are public (php-src spl_iterators.stub.php).
 */

$classes = [
    FilterIterator::class,
    RecursiveFilterIterator::class,
    CallbackFilterIterator::class,
    RegexIterator::class,
    ParentIterator::class,
    RecursiveCallbackFilterIterator::class,
    RecursiveRegexIterator::class,
];

foreach ($classes as $c) {
    $r = new ReflectionMethod($c, 'accept');
    if (!$r->isPublic()) {
        echo "fail: {$c}::accept not public\n";
        exit(1);
    }
    if (!in_array('accept', get_class_methods($c) ?: [], true)) {
        echo "fail: {$c} accept missing from get_class_methods\n";
        exit(1);
    }
}

$fi = new ReflectionMethod(FilterIterator::class, 'accept');
if (!$fi->isAbstract()) {
    echo "fail: FilterIterator::accept not abstract\n";
    exit(1);
}

// Runtime: CallbackFilterIterator still filters.
$it = new CallbackFilterIterator(new ArrayIterator([1, 2, 3, 4]), static fn ($v) => $v % 2 === 0);
$out = [];
foreach ($it as $v) {
    $out[] = $v;
}
if ($out !== [2, 4]) {
    echo 'fail: CallbackFilterIterator foreach '.json_encode($out)."\n";
    exit(1);
}

echo "ok\n";
