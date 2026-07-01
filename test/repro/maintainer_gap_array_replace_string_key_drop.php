<?php

declare(strict_types=1);

/**
 * Issue #13759 — array_replace() inline array_keys() overlay must preserve string-key base entries.
 */

$inline = array_replace(['a' => 1], array_keys(['b' => 2]));
$expect = ['a' => 1, 0 => 'b'];
if ($inline !== $expect) {
    fwrite(STDERR, 'inline: '.var_export($inline, true).' expected '.var_export($expect, true)."\n");
    exit(1);
}

$overlay = array_keys(['b' => 2]);
$variable = array_replace(['a' => 1], $overlay);
if ($variable !== $expect) {
    fwrite(STDERR, 'variable: '.var_export($variable, true).' expected '.var_export($expect, true)."\n");
    exit(1);
}

echo "ok\n";
