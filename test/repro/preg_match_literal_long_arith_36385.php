<?php

declare(strict_types=1);

/**
 * Thin-AOT preg_match / preg_match_all literal path must not SIGSEGV (#36385).
 *
 * 1) Overflow i1 flags spill to entry allocas so materialize dominates under NestedJIT.
 * 2) match_all count-only avoids matchAllStore static prologue + rematchCount re-entry crash.
 *
 * php-src: ext/pcre/php_pcre.c php_pcre_match_impl / php_pcre_match_all
 */
echo preg_match('/a/', 'a'), '|', preg_match_all('/a/', ''), '|', preg_match_all('/a/', 'a'), '|', preg_match_all('/a/', 'aa'), "\n";
for ($i = 0; $i < 3; ++$i) {
    echo preg_match_all('/a/', 'aa'), '|';
}
echo "\n";
