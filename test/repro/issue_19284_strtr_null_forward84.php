<?php
/** Repro #19284 — strtr/strcspn/strspn/strip_tags/nl2br null TypeError on PHP_COMPILER_PROFILE=8.4. */
$cases = [
    'strtr' => static fn () => strtr(null, 'a', 'b'),
    'strcspn' => static fn () => strcspn(null, 'a'),
    'strspn' => static fn () => strspn(null, 'a'),
    'strip_tags' => static fn () => strip_tags(null),
    'nl2br' => static fn () => nl2br(null),
];
foreach ($cases as $name => $fn) {
    try {
        var_export($fn());
        echo " $name:OK\n";
    } catch (Throwable $e) {
        echo $name.': '.get_class($e)."\n";
    }
}
