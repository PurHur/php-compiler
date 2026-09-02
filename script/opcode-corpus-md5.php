#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Opcode dump corpus gate for Compiler.php trait extractions (#36230).
 *
 * Compiles every `test/differential/cases/*.php` file and fingerprints
 * `PHPCompiler\Printer::print` output. Trait moves must keep this table
 * byte-identical (zero opcode diff). Empty corpora are a fail (#36248).
 *
 * Usage:
 *   php script/opcode-corpus-md5.php           # check vs committed baseline
 *   php script/opcode-corpus-md5.php --check
 *   php script/opcode-corpus-md5.php --update  # rewrite baseline
 */

$root = dirname(__DIR__);
chdir($root);

require $root.'/vendor/autoload.php';

$update = in_array('--update', $argv, true);
$check = !$update; // default is check; --check is accepted for clarity
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    fwrite(STDOUT, "Usage: php script/opcode-corpus-md5.php [--check|--update]\n");
    exit(0);
}

const OPCODE_CORPUS_MIN_FILES = 100;
const OPCODE_CORPUS_GLOB = 'test/differential/cases/*.php';
const OPCODE_CORPUS_BASELINE = 'test/differential/OPCODE-CORPUS.md5';

$globOverride = getenv('OPCODE_CORPUS_GLOB_OVERRIDE');
$corpusGlob = (false !== $globOverride && '' !== $globOverride)
    ? $globOverride
    : OPCODE_CORPUS_GLOB;

$files = glob($corpusGlob);
if (false === $files) {
    fwrite(STDERR, "opcode-corpus-md5: glob failed for {$corpusGlob}\n");
    exit(1);
}
sort($files, SORT_STRING);

if (0 === count($files)) {
    fwrite(STDERR, "opcode-corpus-md5: empty corpus is not a pass (#36248) — no files matched {$corpusGlob}\n");
    exit(1);
}
// Honesty probes may force an empty/tiny glob via OPCODE_CORPUS_GLOB_OVERRIDE.
if (false === $globOverride || '' === $globOverride) {
    if (count($files) < OPCODE_CORPUS_MIN_FILES) {
        fwrite(STDERR, sprintf(
            "opcode-corpus-md5: corpus too small (%d < %d) — refusing to treat a thin set as a gate (#36230)\n",
            count($files),
            OPCODE_CORPUS_MIN_FILES
        ));
        exit(1);
    }
}

$runtime = new PHPCompiler\Runtime();
$printer = new PHPCompiler\Printer();
$parts = [];
$t0 = hrtime(true);

foreach ($files as $rel) {
    $code = file_get_contents($rel);
    if (false === $code) {
        fwrite(STDERR, "opcode-corpus-md5: cannot read {$rel}\n");
        exit(1);
    }
    try {
        $script = $runtime->parse($code, $rel);
        $block = $runtime->compile($script);
    } catch (Throwable $e) {
        fwrite(STDERR, "opcode-corpus-md5: compile failed for {$rel}: ".$e->getMessage()."\n");
        exit(1);
    }
    if (null === $block) {
        fwrite(STDERR, "opcode-corpus-md5: compile returned null for {$rel}\n");
        exit(1);
    }
    $parts[$rel] = md5($printer->print($block));
}

ksort($parts, SORT_STRING);
$blob = '';
foreach ($parts as $path => $hash) {
    $blob .= $path."\t".$hash."\n";
}
$aggregate = md5($blob);
$elapsed = (hrtime(true) - $t0) / 1e9;

if ($update) {
    if (false === file_put_contents(OPCODE_CORPUS_BASELINE, $blob)) {
        fwrite(STDERR, 'opcode-corpus-md5: cannot write '.OPCODE_CORPUS_BASELINE."\n");
        exit(1);
    }
    fwrite(STDOUT, sprintf(
        "opcode-corpus-md5: UPDATED %s (%d files, aggregate %s, %.2fs)\n",
        OPCODE_CORPUS_BASELINE,
        count($parts),
        $aggregate,
        $elapsed
    ));
    exit(0);
}

if (!$check) {
    fwrite(STDERR, "opcode-corpus-md5: internal mode error\n");
    exit(1);
}

$baselineRaw = @file_get_contents(OPCODE_CORPUS_BASELINE);
if (false === $baselineRaw || '' === $baselineRaw) {
    fwrite(STDERR, 'opcode-corpus-md5: missing baseline '.OPCODE_CORPUS_BASELINE." — run with --update (#36230)\n");
    exit(1);
}

if ($baselineRaw === $blob) {
    fwrite(STDOUT, sprintf(
        "opcode-corpus-md5: OK (%d files, aggregate %s, %.2fs)\n",
        count($parts),
        $aggregate,
        $elapsed
    ));
    exit(0);
}

$baselineParts = [];
foreach (preg_split("/\r\n|\n|\r/", trim($baselineRaw)) as $line) {
    if ('' === $line) {
        continue;
    }
    $tab = strpos($line, "\t");
    if (false === $tab) {
        fwrite(STDERR, "opcode-corpus-md5: malformed baseline line: {$line}\n");
        exit(1);
    }
    $baselineParts[substr($line, 0, $tab)] = substr($line, $tab + 1);
}

$changed = [];
$added = [];
$removed = [];
foreach ($parts as $path => $hash) {
    if (!isset($baselineParts[$path])) {
        $added[] = $path;
    } elseif ($baselineParts[$path] !== $hash) {
        $changed[] = $path;
    }
}
foreach ($baselineParts as $path => $_) {
    if (!isset($parts[$path])) {
        $removed[] = $path;
    }
}

fwrite(STDERR, sprintf(
    "opcode-corpus-md5: DRIFT — aggregate %s != baseline %s (%d changed, %d added, %d removed)\n",
    $aggregate,
    md5($baselineRaw),
    count($changed),
    count($added),
    count($removed)
));
$show = static function (string $label, array $list): void {
    if ([] === $list) {
        return;
    }
    fwrite(STDERR, "  {$label}:\n");
    foreach (array_slice($list, 0, 20) as $path) {
        fwrite(STDERR, "    {$path}\n");
    }
    if (count($list) > 20) {
        fwrite(STDERR, '    ... and '.(count($list) - 20)." more\n");
    }
};
$show('changed', $changed);
$show('added', $added);
$show('removed', $removed);
fwrite(STDERR, "opcode-corpus-md5: if the opcode change is intentional, refresh with: php script/opcode-corpus-md5.php --update\n");
exit(1);
