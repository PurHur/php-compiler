<?php
// Repro for #21077 — Collator string args TypeError on PROFILE=8.4 (Z_PARAM_STR)
$c = collator_create('en');
foreach ([
    'proc_compare' => static fn () => collator_compare($c, null, 'a'),
    'method_compare' => static fn () => (new Collator('en'))->compare(null, 'a'),
    'proc_sort_key' => static fn () => collator_get_sort_key($c, null),
    'method_sort_key' => static fn () => (new Collator('en'))->getSortKey(null),
] as $n => $fn) {
    try {
        var_export($fn());
        echo " $n\n";
    } catch (Throwable $e) {
        echo "$n: ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
