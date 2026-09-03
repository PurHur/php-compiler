<?php

declare(strict_types=1);

/**
 * Regex-redux (scaled) — preg_replace / preg_match_all (#36385).
 */

$seq = str_repeat('agcttttcattctgactgcaacgggcaatatgtctctgtgtggattaaaaaaagagtgtctgatagcagc', 80);
$ilen = strlen($seq);

$variants = [
    'agggtaaa|tttaccct',
    '[cgt]gggtaaa|tttaccc[acg]',
    'a[act]ggtaaa|tttacc[agt]t',
    'ag[act]gtaaa|tttac[agt]ct',
    'agg[act]taaa|ttta[agt]cct',
];

$counts = [];
foreach ($variants as $pat) {
    $counts[$pat] = preg_match_all('/'.$pat.'/i', $seq);
}

$seq2 = preg_replace('/t{3,}/i', 'TTT', $seq);
$seq2 = preg_replace('/a{3,}/i', 'AAA', (string) $seq2);

echo $ilen, '|', strlen((string) $seq2);
foreach ($variants as $pat) {
    echo '|', $counts[$pat];
}
echo "\n";
