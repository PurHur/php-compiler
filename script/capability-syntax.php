#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate docs/capabilities-syntax.md (language constructs: OOP, match, arrow fn).
 *
 * Usage:
 *   php script/capability-syntax.php          # write docs/capabilities-syntax.md
 *   php script/capability-syntax.php --check  # exit 1 if committed file is stale
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/capability-syntax-lib.php';

$check = in_array('--check', $argv, true);
$outFile = $root . '/docs/capabilities-syntax.md';

$handlers = collectOpcodeHandlers($root);
$syntax = collectSyntaxCapabilities($root, syntaxRowDefinitions(), $handlers);
$markdown = renderSyntaxMarkdown($syntax)
    . renderWebNorthStarMarkdown(webNorthStarDefinitions())
    . renderMiniWebAppOopNorthStarMarkdown(miniWebAppOopNorthStarDefinitions())
    . renderSessionsWebNorthStarMarkdown(sessionsWebNorthStarDefinitions())
    . renderFileUploadWebNorthStarMarkdown(fileUploadWebNorthStarDefinitions())
    . renderThrowsWebNorthStarMarkdown(throwsWebNorthStarDefinitions());

if ($check) {
    if (!is_file($outFile)) {
        fwrite(STDERR, "Missing $outFile — run: php script/capability-syntax.php\n");
        exit(1);
    }
    $committed = (string) file_get_contents($outFile);
    if ($committed !== $markdown) {
        fwrite(STDERR, "docs/capabilities-syntax.md is out of date — run: php script/capability-syntax.php\n");
        exit(1);
    }
    fwrite(STDOUT, 'docs/capabilities-syntax.md is up to date (' . count($syntax) . " constructs).\n");
    exit(0);
}

if (!is_dir(dirname($outFile))) {
    mkdir(dirname($outFile), 0755, true);
}
file_put_contents($outFile, $markdown);
fwrite(STDOUT, "Wrote $outFile (" . count($syntax) . " constructs).\n");
