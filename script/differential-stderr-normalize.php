<?php

declare(strict_types=1);

/**
 * Path-prefix normalise for {@see script/differential-sweep.sh} `--stderr` (#36383).
 *
 * Keeps file:line numbers (needed for include-site Zend parity) but strips the
 * checkout / `/compiler` prefix so host vs image paths compare equal.
 */

$root = $argv[1] ?? '';
if ('' === $root || !is_dir($root)) {
    fwrite(STDERR, "differential-stderr-normalize: need repo root\n");
    exit(2);
}

$text = stream_get_contents(STDIN);
if (false === $text) {
    exit(2);
}

$replacements = [];
$real = realpath($root);
if (false !== $real) {
    $replacements[] = $real;
}
$replacements[] = rtrim($root, '/');
$replacements[] = '/compiler';

usort($replacements, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
foreach ($replacements as $prefix) {
    if ('' === $prefix) {
        continue;
    }
    $text = str_replace($prefix, '.', $text);
}

echo $text;
