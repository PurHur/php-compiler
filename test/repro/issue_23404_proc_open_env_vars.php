<?php
declare(strict_types=1);

$rf = new ReflectionFunction('proc_open');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
$pipes = [];
$cmd = ['php', '-r', 'echo getenv("X");'];
$spec = [1 => ['pipe', 'w']];
$env = ['X' => 'ok'];
try {
    $h = proc_open(
        command: $cmd,
        descriptor_spec: $spec,
        pipes: $pipes,
        env_vars: $env
    );
    if ($h) {
        echo stream_get_contents($pipes[1]);
        proc_close($h);
        echo "\n";
    }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
