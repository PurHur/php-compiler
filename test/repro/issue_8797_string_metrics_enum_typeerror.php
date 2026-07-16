<?php
/**
 * Issue #8797 — str_word_count/strrev/chunk_split/similar_text/levenshtein/substr_count
 * must TypeError on backed enum case string operands (php-src-strict).
 *
 * php-src: ext/standard/string.c — Z_PARAM_STR paths
 */
enum E: int { case A = 1; }
$p = 0;
$tests = [
    'str_word_count' => static fn () => str_word_count(E::A),
    'strrev' => static fn () => strrev(E::A),
    'chunk_split' => static fn () => chunk_split(E::A, 1),
    'similar_text' => static fn () => similar_text(E::A, 'x', $p),
    'levenshtein' => static fn () => levenshtein(E::A, 'x'),
    'substr_count' => static fn () => substr_count(E::A, '1'),
];
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo $name, ": uncaught\n";
    } catch (TypeError $e) {
        echo $name, ": TypeError\n";
    } catch (Throwable $e) {
        echo $name, ': ', $e::class, "\n";
    }
}
