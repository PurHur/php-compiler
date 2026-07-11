<?php

declare(strict_types=1);

$expected = <<<'TXT'
array (
  0 => 150.0,
)
array (
  0 => 0.15,
)
array (
  0 => 2500.0,
)

TXT;

ob_start();
var_export(sscanf('1.5e2', '%f'));
echo "\n";
var_export(sscanf('1.5E-1', '%f'));
echo "\n";
var_export(sscanf('  2.5e+3xyz', '%f'));
echo "\n";
$out = ob_get_clean();

if ($out !== $expected) {
    echo $out;
    exit(1);
}
echo "ok\n";
