<?php
declare(strict_types=1);
error_reporting(E_ALL);

$cases = [
    'posix_kill' => fn() => posix_kill(null, 0),
    'posix_getpwuid' => fn() => posix_getpwuid(null),
    'posix_getgrgid' => fn() => posix_getgrgid(null),
    'posix_getpwnam' => fn() => posix_getpwnam(null),
    'posix_getgrnam' => fn() => posix_getgrnam(null),
    'posix_getsid' => fn() => posix_getsid(null),
    'posix_getpgid' => fn() => posix_getpgid(null),
    'posix_setuid' => fn() => posix_setuid(null),
    'pcntl_alarm' => fn() => pcntl_alarm(null),
    'pcntl_wifexited' => fn() => pcntl_wifexited(null),
    'pcntl_wexitstatus' => fn() => pcntl_wexitstatus(null),
];

$pass = 0;
$fail = 0;
foreach ($cases as $name => $fn) {
    try {
        $result = $fn();
        echo "FAIL $name: no TypeError, got " . var_export($result, true) . "\n";
        ++$fail;
    } catch (\TypeError $e) {
        echo "PASS $name: " . $e->getMessage() . "\n";
        ++$pass;
    } catch (\Throwable $e) {
        echo "FAIL $name: " . get_class($e) . ": " . $e->getMessage() . "\n";
        ++$fail;
    }
}
echo "\n$pass passed, $fail failed\n";
