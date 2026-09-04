#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate docs/capabilities-syntax.md (language constructs: OOP, match, arrow fn).
 *
 * Usage:
 *   php script/capability-syntax.php                 # write docs/capabilities-syntax.md
 *   php script/capability-syntax.php --check         # exit 1 if committed file is stale
 *   php script/capability-syntax.php --refresh-probes # re-run VM execute probes + write cache (#36384)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/capability-syntax-lib.php';

$check = in_array('--check', $argv, true);
$refreshProbes = in_array('--refresh-probes', $argv, true);
$outFile = $root . '/docs/capabilities-syntax.md';

$definitions = syntaxRowDefinitions();
if ($refreshProbes) {
    $cache = refreshSyntaxProbeCache($root, $definitions);
    fwrite(STDOUT, 'capability-syntax: refreshed probe cache (' . count($cache['rows'] ?? []) . " rows, fp="
        . substr((string) ($cache['lowering_fingerprint'] ?? ''), 0, 12) . "…)\n");
}

$handlers = collectOpcodeHandlers($root);
$syntax = collectSyntaxCapabilities($root, $definitions, $handlers, loadSyntaxProbeCache($root));
$markdown = renderSyntaxMarkdown($syntax)
    . renderStdlibArrayBuiltinNorthStarMarkdown(stdlibArrayBuiltinNorthStarDefinitions())
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
