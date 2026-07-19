<?php
// Repro #20937 — IntlChar::enumCharTypes (php-src-strict / ICU u_enumCharTypes).
echo 'enumCharTypes=', method_exists('IntlChar', 'enumCharTypes') ? 'yes' : 'no', "\n";
$n = 0;
$first = [];
$digit = null;
$upper = null;
IntlChar::enumCharTypes(function ($start, $limit, $type) use (&$n, &$first, &$digit, &$upper) {
    if ($n < 5) {
        $first[] = $start.'-'.$limit.':'.$type;
    }
    if (null === $digit && $start <= 0x30 && $limit > 0x30) {
        $digit = $start.'-'.$limit.':'.$type;
    }
    if (null === $upper && $start <= 0x41 && $limit > 0x41) {
        $upper = $start.'-'.$limit.':'.$type;
    }
    ++$n;
});
echo 'calls=', $n, "\n";
echo 'first=', implode('|', $first), "\n";
echo 'digit=', $digit, "\n";
echo 'upper=', $upper, "\n";
