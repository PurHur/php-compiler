<?php
/**
 * Issue #19320 — preg_quote/match/match_all/split null string args TypeError on PROFILE=8.4.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_19320_pcre_null_forward84.php
 */
foreach ([
    'preg_quote' => static fn () => preg_quote(null),
    'preg_match' => static fn () => preg_match('/./', null),
    'preg_match_all' => static fn () => preg_match_all('/./', null),
    'preg_split' => static fn () => preg_split('/./', null),
] as $name => $fn) {
    try {
        $fn();
        echo $name, " COERCE\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), "\n";
    }
}
