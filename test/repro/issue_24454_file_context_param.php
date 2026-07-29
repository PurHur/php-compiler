<?php
/**
 * #24454 — file() Reflection arity/names + positional/named $context (ext/standard/file.stub.php)
 */
$rf = new ReflectionFunction('file');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'arity=', $rf->getNumberOfParameters(), ' names=', implode(',', $names), "\n";

$ctx = stream_context_create([]);
$path = sys_get_temp_dir() . '/phpc-issue-24454-' . getmypid() . '.txt';
file_put_contents($path, "a\nb\n");

try {
    $lines = file($path, 0, $ctx);
    echo 'positional=', is_array($lines) ? count($lines) : 'fail', "\n";
} catch (Throwable $e) {
    echo 'positional=', $e->getMessage(), "\n";
}

try {
    $lines = file(filename: $path, flags: 0, context: $ctx);
    echo 'named=', is_array($lines) ? count($lines) : 'fail', "\n";
} catch (Throwable $e) {
    echo 'named=', $e->getMessage(), "\n";
}

@unlink($path);
