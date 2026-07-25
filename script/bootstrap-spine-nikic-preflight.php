<?php

declare(strict_types=1);

/**
 * Parse every spine inventory PHP file with nikic/php-parser (#22642).
 *
 * Exclusive Zend full-spine refresh spent ~226m before hitting a premature
 * docblock close in lib/VM/SplArrayCastJitHelper.php (DateTime-star-slash).
 * Fail fast before bootstrap-refresh-gen0-sidecar starts honest AOT.
 *
 * Usage: php script/bootstrap-spine-nikic-preflight.php
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require $root.'/script/bootstrap-spine-count.php';

$spineEntry = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
$paths = bootstrap_spine_require_paths($spineEntry);
if ($paths === []) {
    fwrite(STDERR, "bootstrap-spine-nikic-preflight: no require_once paths in spine entry\n");
    exit(2);
}

$parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
$failures = [];
$ok = 0;

foreach ($paths as $rel) {
    $path = $root.'/'.$rel;
    if (!is_file($path)) {
        $failures[] = "{$rel}: missing file";
        continue;
    }
    if (!str_ends_with($rel, '.php')) {
        continue;
    }
    $source = (string) file_get_contents($path);
    try {
        $parser->parse($source);
        ++$ok;
    } catch (Throwable $e) {
        $failures[] = "{$rel}: ".$e->getMessage();
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'bootstrap-spine-nikic-preflight: FAILED ('.count($failures)." error(s))\n");
    foreach (array_slice($failures, 0, 20) as $line) {
        fwrite(STDERR, "  {$line}\n");
    }
    if (count($failures) > 20) {
        fwrite(STDERR, '  ... and '.(count($failures) - 20)." more\n");
    }
    exit(1);
}

fwrite(STDOUT, "bootstrap-spine-nikic-preflight: OK {$ok}/{$ok} spine PHP files parse\n");
exit(0);
