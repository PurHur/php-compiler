<?php

declare(strict_types=1);

/**
 * Repro for #25055 — Spoofchecker by-ref errorCode + Reflection / named args.
 *
 * Default Runtime withholds Spoofchecker until extension_loaded('intl').
 * Force-register to exercise the in-tree implementation.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;

$runtime = new Runtime();
BuiltinClasses::registerSpoofchecker($runtime->vmContext);

$code = <<<'PHP'
<?php
$s = new Spoofchecker();
$err = -1;
$r = $s->isSuspicious("раураl.com", $err);
echo 'r=', $r ? '1' : '0', ' err=', $err, "\n";
$err2 = -1;
$r2 = $s->isSuspicious(string: "раураl.com", errorCode: $err2);
echo 'named_r=', $r2 ? '1' : '0', ' err=', $err2, "\n";
$m = new ReflectionMethod('Spoofchecker', 'isSuspicious');
echo 'params=', $m->getNumberOfParameters(), "\n";
foreach ($m->getParameters() as $p) {
    $type = $p->getType();
    echo $p->getName(),
        ' byref=', (int) $p->isPassedByReference(),
        ' opt=', (int) $p->isOptional(),
        ' type=', null !== $type ? (string) $type : '-',
        ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'N/A',
        "\n";
}
$err3 = -1;
$r3 = $s->areConfusable('google', 'goog1e', $err3);
echo 'conf=', $r3 ? '1' : '0', ' err=', $err3, "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_25055_spoofchecker_errorcode.php');
$runtime->run($block);
