<?php

declare(strict_types=1);

/**
 * Maintainer probe: MCJIT execute for user-class property access (#5111).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/LlvmToolchain.php';

$cases = [
    'empty_class_echo' => <<<'PHP'
<?php
class C {}
try {
    echo new C();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP,
    'untyped_prop' => <<<'PHP'
<?php
class C {
    public $x = 1;
}
$c = new C();
echo $c->x, "\n";
PHP,
    'typed_prop' => <<<'PHP'
<?php
class C {
    public int $x = 1;
}
$c = new C();
echo $c->x, "\n";
PHP,
    'dynamic_write' => <<<'PHP'
<?php
class C {
    public int $x = 1;
}
$c = new C();
$c->y = 2;
echo $c->y, "\n";
PHP,
];

$root = dirname(__DIR__, 2);
$prefix = PHPCompiler\LlvmToolchain::envPrefix($root);
$env = [];
foreach (array_merge($_ENV, $_SERVER) as $k => $v) {
    if (is_string($v)) {
        $env[$k] = $v;
    }
}
unset($env['PHP_COMPILER_SKIP_LLVM_PRELOAD']);
PHPCompiler\LlvmToolchain::applyProcessEnv($env, $root);

$dir = $root . '/var';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

foreach ($cases as $label => $code) {
    $path = $dir . '/jit_mcjit_' . $label . '.php';
    file_put_contents($path, $code);
    $cmd = array_merge($prefix, [PHP_BINARY, $root . '/bin/jit.php', $path]);
    $pipes = [];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    echo $label, ': exit=', $exit, ' out=', trim($out), ' err=', trim($err), "\n";
}
