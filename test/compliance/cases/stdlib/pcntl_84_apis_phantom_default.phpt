--TEST--
stdlib pcntl PHP 8.4 APIs withheld on default 8.4.0-dev reference (#26742, ext/pcntl/pcntl.stub.php)
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'pcntl_getcpu',
    'pcntl_getcpuaffinity',
    'pcntl_setcpuaffinity',
    'pcntl_setns',
    'pcntl_waitid',
    'pcntl_fork',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}

$internal = get_defined_functions()['internal'];
foreach (['pcntl_getcpu', 'pcntl_getcpuaffinity', 'pcntl_setcpuaffinity', 'pcntl_setns', 'pcntl_waitid'] as $fn) {
    echo 'defined_', $fn, '=', in_array($fn, $internal, true) ? 'Y' : 'N', "\n";
}
?>
--EXPECT--
pcntl_getcpu=false
pcntl_getcpuaffinity=false
pcntl_setcpuaffinity=false
pcntl_setns=false
pcntl_waitid=false
pcntl_fork=true
defined_pcntl_getcpu=N
defined_pcntl_getcpuaffinity=N
defined_pcntl_setcpuaffinity=N
defined_pcntl_setns=N
defined_pcntl_waitid=N
