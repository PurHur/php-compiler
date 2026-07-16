--TEST--
stdlib pcntl_wait/alarm/exec surface + fork wait exit (#19565, ext/pcntl/pcntl.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['pcntl_wait', 'pcntl_alarm', 'pcntl_exec', 'pcntl_waitpid', 'pcntl_wifsignaled', 'pcntl_wifstopped', 'pcntl_wstopsig', 'pcntl_wtermsig'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}

if (!function_exists('pcntl_fork') || !function_exists('pcntl_wait')) {
    echo "skip\n";
    exit(0);
}

$prev = pcntl_alarm(0);
pcntl_alarm(9);
$left = pcntl_alarm(0);
echo 'alarm_ok=', ($left >= 1 && $left <= 9) ? 'Y' : 'N', "\n";

$execRc = @pcntl_exec('/no/such/pcntl_exec_19565');
echo 'exec_fail=', (false === $execRc) ? 'Y' : 'N', "\n";

$pid = pcntl_fork();
if (-1 === $pid) {
    echo "fork fail\n";
    exit(0);
}
if (0 === $pid) {
    exit(7);
}
$status = -1;
$waitRc = pcntl_wait($status);
if ($waitRc <= 0) {
    echo "wait fail\n";
    exit(0);
}
if (!pcntl_wifexited($status)) {
    echo "not exited\n";
    exit(0);
}
echo 'exit=', pcntl_wexitstatus($status), "\n";
echo 'wifsig=', pcntl_wifsignaled($status) ? '1' : '0', "\n";
--EXPECT--
pcntl_wait=Y
pcntl_alarm=Y
pcntl_exec=Y
pcntl_waitpid=Y
pcntl_wifsignaled=Y
pcntl_wifstopped=Y
pcntl_wstopsig=Y
pcntl_wtermsig=Y
alarm_ok=Y
exec_fail=Y
exit=7
wifsig=0
