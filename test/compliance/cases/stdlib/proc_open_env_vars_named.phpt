--TEST--
stdlib proc_open() Zend stub env_vars named param (#23404, ext/standard/proc_open.stub.php)
--FILE--
<?php
declare(strict_types=1);

$rf = new ReflectionFunction('proc_open');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";

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

$pipes2 = [];
try {
    proc_open(
        command: $cmd,
        descriptor_spec: $spec,
        pipes: $pipes2,
        env: ['X' => 'nope']
    );
    echo "legacy_env_ok\n";
} catch (Throwable $e) {
    echo 'legacy: ', $e->getMessage(), "\n";
}
--EXPECT--
command,descriptor_spec,pipes,cwd,env_vars,options
ok
legacy: Unknown named parameter $env
