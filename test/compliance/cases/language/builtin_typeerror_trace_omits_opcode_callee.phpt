--TEST--
language opcode-builtin TypeError getTrace attributes caller not callee (issue #28852, Zend/zend_execute.c)
--FILE--
<?php
ini_set('zend.exception_ignore_args', '0');

function user(): void
{
    strlen([]);
}

try {
    user();
} catch (Throwable $e) {
    $trace = $e->getTrace();
    echo ($trace[0]['function'] ?? '?'), "\n";
    echo (str_contains($e->getTraceAsString(), 'user()') ? 'has_user' : 'no_user'), "\n";
    echo (str_contains($e->getTraceAsString(), 'strlen()') ? 'has_strlen' : 'no_strlen'), "\n";
}

try {
    strlen([]);
} catch (Throwable $e) {
    $bare = $e->getTrace();
    echo count($bare), "\n";
    echo (isset($bare[0]['function']) ? $bare[0]['function'] : 'empty'), "\n";
}

// ArgumentCountError still attributes strlen (not opcode TypeError path).
function user_argc(): void
{
    strlen();
}
try {
    user_argc();
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo ($e->getTrace()[0]['function'] ?? '?'), "\n";
}
?>
--EXPECT--
user
has_user
no_strlen
0
empty
ArgumentCountError
strlen
