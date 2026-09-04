<?php
// Discarded levenshtein / str_getcsv (4-arg) / number_format on typed args (#36386).
// Side-effect-free statements only — results unused where discarded.
// @differential-repeat: 3
function work(string $a, string $b, string $line, float $n, int $loops): string
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        levenshtein($a, $b);
        levenshtein($a, $b, 1, 1, 1);
        str_getcsv($line, ',', '"', '\\');
        number_format($n);
        number_format($n, 2, '.', ',');
        $c += $k;
    }
    $d = levenshtein($a, $b);
    $row = str_getcsv($line, ',', '"', '\\');
    $fmt = number_format($n, 2, '.', ',');

    return $c.'|'.$d.'|'.implode(';', $row).'|'.$fmt;
}
echo work('kitten', 'sitting', 'a,b,c', 1234.5, 5), "\n";
echo work('abc', 'xyz', '1,2', 7.0, 3), "\n";
echo work('same', 'same', 'x', 0.5, 2), "\n";
