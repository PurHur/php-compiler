--TEST--
Language: nested function Exception::getTrace() keeps args like Zend (#29207)
--FILE--
<?php
ini_set('zend.exception_ignore_args', '0');
(function () {
    function nest($a, $b) {
        throw new Exception('x');
    }
    try {
        nest('A', 'B');
    } catch (Throwable $e) {
        $frame = $e->getTrace()[0] ?? [];
        echo 'exception_fn=', json_encode($frame['function'] ?? null), "\n";
        echo 'exception_args=', json_encode($frame['args'] ?? null), "\n";
    }
    function nest2($a, $b) {
        $bt = debug_backtrace(0, 1);
        echo 'bt_args=', json_encode($bt[0]['args'] ?? null), "\n";
    }
    nest2('C', 'D');

    function nest_sensitive(#[\SensitiveParameter] $secret, $ok) {
        throw new Exception('s');
    }
    try {
        nest_sensitive('hunter2', 'visible');
    } catch (Throwable $e) {
        $args = $e->getTrace()[0]['args'] ?? [];
        echo 'sensitive_fn=', ($e->getTrace()[0]['function'] ?? ''), "\n";
        echo 'sensitive_argc=', count($args), "\n";
        echo isset($args[0]) && is_object($args[0]) ? get_class($args[0]) : 'missing', "\n";
        echo isset($args[1]) ? var_export($args[1], true) : 'missing', "\n";
    }
})();
--EXPECT--
exception_fn="nest"
exception_args=["A","B"]
bt_args=["C","D"]
sensitive_fn=nest_sensitive
sensitive_argc=2
SensitiveParameterValue
'visible'
