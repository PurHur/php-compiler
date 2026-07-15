<?php

// #19161 — Z_PARAM_STR/Z_PARAM_LONG null coerces on PHP_COMPILER_PROFILE=8.4 (ext/standard/string.c).
// trim()/ltrim()/rtrim()/chop() now TypeError on 8.4 (#19254); covered separately.

$checks = [
    ['dirname(null)', dirname(null)],
    ['explode(",", null)', explode(',', null)],
    ['ord(null)', ord(null)],
    ['chr(null)', chr(null)],
    ['parse_url(null)', parse_url(null)],
];
$failed = 0;
foreach ($checks as [$label, $result]) {
    echo $label, ' => ';
    var_export($result);
    echo "\n";
    if ('dirname(null)' === $label && '' !== $result) {
        ++$failed;
    }
    if ('explode(",", null)' === $label && [''] !== $result) {
        ++$failed;
    }
    if ('ord(null)' === $label && 0 !== $result) {
        ++$failed;
    }
    if ('chr(null)' === $label && "\0" !== $result) {
        ++$failed;
    }
    if ('parse_url(null)' === $label && !\is_array($result)) {
        ++$failed;
    }
}
exit($failed > 0 ? 1 : 0);
