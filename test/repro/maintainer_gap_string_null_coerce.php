<?php

// #19318 — chr(null) TypeError on 8.4; dirname/parse_url still coerce (#19161 leftovers).
// trim()/ltrim()/rtrim()/chop() TypeError on 8.4 (#19254); covered separately.

$checks = [
    ['dirname(null)', static fn () => dirname(null)],
    ['explode(",", null)', static fn () => explode(',', null)],
    ['ord(null)', static fn () => ord(null)],
    ['chr(null)', static fn () => chr(null)],
    ['parse_url(null)', static fn () => parse_url(null)],
];
$failed = 0;
foreach ($checks as [$label, $factory]) {
    try {
        $result = $factory();
        echo $label, ' => ';
        var_export($result);
        echo "\n";
        if ('dirname(null)' === $label && '' !== $result) {
            ++$failed;
        }
        if ('parse_url(null)' === $label && !\is_array($result)) {
            ++$failed;
        }
        if (\in_array($label, ['explode(",", null)', 'ord(null)', 'chr(null)'], true)) {
            ++$failed; // expected TypeError
        }
    } catch (TypeError $e) {
        echo $label, ' => TypeError: ', $e->getMessage(), "\n";
        if (!\in_array($label, ['explode(",", null)', 'ord(null)', 'chr(null)'], true)) {
            ++$failed;
        }
    }
}
exit($failed > 0 ? 1 : 0);
