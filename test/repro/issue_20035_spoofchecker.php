<?php

declare(strict_types=1);

/**
 * Repro for #20035 — Spoofchecker (deferred from #6171).
 *
 * Default Runtime withholds Spoofchecker until extension_loaded('intl').
 * Force-register to exercise the in-tree implementation (same pattern as #6171 tests).
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;

$runtime = new Runtime();
BuiltinClasses::registerSpoofchecker($runtime->vmContext);

$code = <<<'PHP'
<?php
echo 'Spoofchecker: ', class_exists('Spoofchecker') ? 'yes' : 'no', "\n";
$s = new Spoofchecker();
echo 'clean=', $s->isSuspicious('paypal.com') ? 'yes' : 'no', "\n";
$mixed = "p\xD0\xB0ypal.com";
echo 'mixed=', $s->isSuspicious($mixed) ? 'yes' : 'no', "\n";
echo 'conf=', $s->areConfusable('paypal', "\xCF\x81aypal") ? 'yes' : 'no', "\n";
echo 'SINGLE_SCRIPT=', Spoofchecker::SINGLE_SCRIPT, "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_20035_spoofchecker.php');
$runtime->run($block);
