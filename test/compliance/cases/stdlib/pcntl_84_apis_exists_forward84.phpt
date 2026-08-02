--TEST--
stdlib pcntl PHP 8.4 APIs advertised on PROFILE=8.4 (#26742, ext/pcntl/pcntl.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
    echo $fn, '=', function_exists($fn) ? 'Y' : 'N', "\n";
}
?>
--EXPECT--
pcntl_getcpu=Y
pcntl_getcpuaffinity=Y
pcntl_setcpuaffinity=Y
pcntl_setns=Y
pcntl_waitid=Y
pcntl_fork=Y
