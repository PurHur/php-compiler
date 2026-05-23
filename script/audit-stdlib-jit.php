#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$rows = [];
foreach (glob($root.'/ext/standard/*.php') as $file) {
    $base = basename($file, '.php');
    if (str_starts_with($base, 'Jit') || str_starts_with($base, 'Vm') || 'Module' === $base) {
        continue;
    }
    $source = (string) file_get_contents($file);
    if (!preg_match('/function\s+call\s*\(/', $source)) {
        continue;
    }
    $name = $base;
    if (preg_match("/parent::__construct\\('\\s*([^']+)\\s*'\\)/", $source, $matches)) {
        $name = $matches[1];
    }
    $rows[] = [
        'name' => $name,
        'file' => substr($file, strlen($root) + 1),
        'hasJitArg' => (bool) preg_match('/JitStringArg|JitLongArg|->jitString\s*\(/', $source),
    ];
}
usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
$missing = array_values(array_filter($rows, static fn (array $row): bool => !$row['hasJitArg']));
$present = array_values(array_filter($rows, static fn (array $row): bool => $row['hasJitArg']));

echo "# Stdlib JIT arg-lowering audit\n\n";
echo "| Metric | Count |\n|--------|------:|\n";
echo '| `call()` implementations | '.count($rows)." |\n";
echo '| With JitStringArg/JitLongArg | '.count($present)." |\n";
echo '| Missing arg helpers | '.count($missing)." |\n";
echo '| Self-host auto-stub batch | '.\PHPCompiler\JIT\SelfHostBuiltinPolicy::autoStubBatchCount()." |\n";
