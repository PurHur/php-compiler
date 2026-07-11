#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$main = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
$lines = file($main, FILE_IGNORE_NEW_LINES) ?: [];

$header = [];
$requires = [];
$footer = [];
$phase = 'header';
/** @var array<string, true> */
$seen = [];

foreach ($lines as $line) {
    if (preg_match("#require_once __DIR__\\.'/\\.\\./\\.\\./\\.\\./([^']+)';#", $line, $m)) {
        $path = $m[1];
        if (!isset($seen[$path])) {
            $seen[$path] = true;
            $requires[] = "require_once __DIR__.'/../../../{$path}';";
        }
        continue;
    }
    if (preg_match("#// VM -r smoke:.*require_once __DIR__\\.'/\\.\\./\\.\\./\\.\\./([^']+)';#", $line, $m)) {
        $path = $m[1];
        if (!isset($seen[$path])) {
            $seen[$path] = true;
            $requires[] = "require_once __DIR__.'/../../../{$path}';";
        }
        continue;
    }
    if (str_contains($line, 'VM -r smoke') || str_contains($line, 'VM driver execute') || str_starts_with(trim($line), '$vm')) {
        $phase = 'footer';
    }
    if ('header' === $phase && !str_contains($line, 'require_once') && !str_contains($line, 'VM -r smoke')) {
        $header[] = $line;
    } elseif ('footer' === $phase) {
        if (str_contains($line, 'VM -r smoke')) {
            continue;
        }
        if (!str_contains($line, 'require_once')) {
            $footer[] = $line;
        }
    }
}

$marker = '// VM -r smoke: bootstrap-selfhost-lib-spine-vm-smoke.sh (#1846).';
// VmClassMethod + intermediate ext bases must load after Func\Internal (M4 spine VM/AOT #1492).
$earlyBootstrap = [
    'lib/OpCode.php',
    'lib/Block.php',
    'lib/Frame.php',
    'lib/Func.php',
    'lib/Handler.php',
    'lib/JIT/Call.php',
    'lib/Func/Internal.php',
    'lib/VM/Builtin/VmClassMethod.php',
    'ext/dom/DomClassMethod.php',
];
$earlySet = array_flip($earlyBootstrap);
/** @var array<string, string> */
$requireByPath = [];
foreach ($requires as $req) {
    if (preg_match("#require_once __DIR__\\.'/\\.\\./\\.\\./\\.\\./([^']+)';#", $req, $m)) {
        $requireByPath[$m[1]] = $req;
    }
}
$earlyRequires = [];
foreach ($earlyBootstrap as $rel) {
    $line = "require_once __DIR__.'/../../../{$rel}';";
    $earlyRequires[] = $requireByPath[$rel] ?? $line;
}
$lateRequires = [];
foreach ($requires as $req) {
    if (!preg_match("#require_once __DIR__\\.'/\\.\\./\\.\\./\\.\\./([^']+)';#", $req, $m)) {
        $lateRequires[] = $req;
        continue;
    }
    if (isset($earlySet[$m[1]])) {
        continue;
    }
    $lateRequires[] = $req;
}
$requires = array_merge($earlyRequires, $lateRequires);
$out = implode("\n", $header)."\n\n".implode("\n", $requires)."\n".$marker."\n".implode("\n", $footer)."\n";
file_put_contents($main, $out);
fwrite(STDOUT, 'bootstrap-spine-repair-main: '.count($requires)." unique requires\n");
