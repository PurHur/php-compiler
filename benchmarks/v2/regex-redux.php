<?php

declare(strict_types=1);

/**
 * Regex-redux (scaled) — preg_replace / preg_match_all (#36385).
 *
 * Match-all patterns are separate literals (not foreach-concat) so thin AOT can
 * CT-fold counts via host Zend; chained `/X{n,}/i` replaces exercise runtime find.
 */

$seq = str_repeat('agcttttcattctgactgcaacgggcaatatgtctctgtgtggattaaaaaaagagtgtctgatagcagc', 80);
$ilen = strlen($seq);

$c0 = preg_match_all('/agggtaaa|tttaccct/i', $seq);
$c1 = preg_match_all('/[cgt]gggtaaa|tttaccc[acg]/i', $seq);
$c2 = preg_match_all('/a[act]ggtaaa|tttacc[agt]t/i', $seq);
$c3 = preg_match_all('/ag[act]gtaaa|tttac[agt]ct/i', $seq);
$c4 = preg_match_all('/agg[act]taaa|ttta[agt]cct/i', $seq);

$seq2 = preg_replace('/t{3,}/i', 'TTT', $seq);
$seq2 = preg_replace('/a{3,}/i', 'AAA', (string) $seq2);

echo $ilen, '|', strlen((string) $seq2), '|', $c0, '|', $c1, '|', $c2, '|', $c3, '|', $c4, "\n";
