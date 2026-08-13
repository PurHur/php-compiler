--TEST--
stdlib JIT: session_id/name/module_name excess argc + session_commit alias ACE (#30684)
--FILE--
<?php
foreach ([
    'session_id' => static fn () => session_id(null, 1),
    'session_name' => static fn () => session_name(null, 1),
    'session_module_name' => static fn () => session_module_name(null, 1),
    'session_commit' => static fn () => session_commit(1),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok_id=', is_string(session_id()) ? '1' : '0', "\n";
echo 'ok_name=', session_name() !== '' ? '1' : '0', "\n";
echo 'ok_module=', session_module_name() !== '' ? '1' : '0', "\n";
--EXPECT--
session_id ArgumentCountError: session_id() expects at most 1 argument, 2 given
session_name ArgumentCountError: session_name() expects at most 1 argument, 2 given
session_module_name ArgumentCountError: session_module_name() expects at most 1 argument, 2 given
session_commit ArgumentCountError: session_commit() expects exactly 0 arguments, 1 given
ok_id=1
ok_name=1
ok_module=1
