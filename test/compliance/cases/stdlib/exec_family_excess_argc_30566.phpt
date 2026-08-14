--TEST--
stdlib exec/system/passthru/shell_exec excess argc ArgumentCountError (#30566, php-src exec.c)
--FILE--
<?php
$cases = [
    'shell_hi' => static function () {
        shell_exec('true', 1);
    },
    'shell_lo' => static function () {
        shell_exec();
    },
    'system' => static function () {
        $r = 0;
        system('true', $r, 1);
    },
    'passthru' => static function () {
        $r = 0;
        passthru('true', $r, 1);
    },
    'exec' => static function () {
        $o = [];
        $r = 0;
        exec('true', $o, $r, 1);
    },
    'exec_lo' => static function () {
        exec();
    },
];
foreach ($cases as $name => $call) {
    try {
        $call();
        echo $name, " OK\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
shell_hi ArgumentCountError: shell_exec() expects exactly 1 argument, 2 given
shell_lo ArgumentCountError: shell_exec() expects exactly 1 argument, 0 given
system ArgumentCountError: system() expects at most 2 arguments, 3 given
passthru ArgumentCountError: passthru() expects at most 2 arguments, 3 given
exec ArgumentCountError: exec() expects at most 3 arguments, 4 given
exec_lo ArgumentCountError: exec() expects at least 1 argument, 0 given
