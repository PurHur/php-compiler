<?php
/**
 * exec/system/passthru/shell_exec excess argc → ArgumentCountError (#30566).
 * php-src: ext/standard/exec.c PHP_FUNCTION(exec|system|passthru|shell_exec)
 */
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
        echo $name, ":OK\n";
    } catch (ArgumentCountError $e) {
        echo $name, ':ArgumentCountError:', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
