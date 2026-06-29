<?php

declare(strict_types=1);

// Repro for #13450 — negative offset inline builtin as non-first user-fn arg returns NULL on VM.
function takeSecond(string $label, mixed $second, mixed $third = null): mixed
{
    return $second;
}

$checks = [
    'substr_neg_len' => takeSecond('substr', substr('hello', -3)),
    'substr_neg_end' => takeSecond('substr', substr('hello', 0, -2)),
    'mb_substr_neg' => takeSecond('mb_substr', mb_substr('hello', -2)),
    'strpos_neg' => takeSecond('strpos', strpos('hello', 'l', -2)),
    'stripos_neg' => takeSecond('stripos', stripos('Hello', 'L', -2)),
    'strrpos_neg' => takeSecond('strrpos', strrpos('hello', 'l', -2)),
    'substr_compare_neg' => takeSecond('substr_compare', substr_compare('abc', 'a', -2)),
];

foreach ($checks as $name => $got) {
    if (null === $got) {
        echo "fail: {$name} returned NULL\n";
        exit(1);
    }
}

echo "ok\n";
