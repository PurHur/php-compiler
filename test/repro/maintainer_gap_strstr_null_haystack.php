<?php
// #18982 — haystack-search builtins null TypeError on PHP_COMPILER_PROFILE=8.4.
foreach ([
    'strtok' => static fn () => strtok(null, '.'),
    'strrpos' => static fn () => strrpos(null, 'x'),
    'strpbrk' => static fn () => strpbrk(null, 'abc'),
    'strstr' => static fn () => strstr(null, 'x'),
    'stristr' => static fn () => stristr(null, 'x'),
    'strrchr' => static fn () => strrchr(null, 'x'),
    'strchr' => static fn () => strchr(null, 'x'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
        exit(1);
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
