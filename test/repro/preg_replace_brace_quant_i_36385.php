<?php

declare(strict_types=1);

/**
 * Chained preg_replace with `/X{n,}/i` on a runtime subject (#36385 regex-redux).
 *
 * Thin AOT previously treated `/…/i` as undelimited (kind=0 → find -1 → SIGSEGV) and
 * had no single-char brace-quant fast path. php-src: ext/pcre/php_pcre.c modifiers + braces.
 */
$seed = 'agcttttcattctgactgcaacgggcaatatgtctctgtgtggattaaaaaaagagtgtctgatagcagc';
if (getenv('NOT_SET_XYZ_36385') !== false) {
    $seed = 'nope';
}
$seq = str_repeat($seed, 10);
$seq2 = preg_replace('/t{3,}/i', 'TTT', $seq);
$seq2 = preg_replace('/a{3,}/i', 'AAA', (string) $seq2);
echo strlen($seq), '|', strlen((string) $seq2), "\n";
