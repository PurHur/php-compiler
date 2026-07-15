<?php

// #19161 — Z_PARAM_STR/Z_PARAM_LONG null coerces on PHP_COMPILER_PROFILE=8.4 (ext/standard/string.c).

$checks = [
    ['trim(null)', trim(null)],
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
    if ('trim(null)' === $label && '' !== $result) {
        ++$failed;
    }
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
