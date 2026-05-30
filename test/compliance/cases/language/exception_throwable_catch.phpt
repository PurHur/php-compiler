--TEST--
Language: catch Throwable matches Exception and Error (issue #195)
--FILE--
<?php
try {
    throw new Exception('ex');
} catch (Throwable $e) {
    echo 'E:', $e->getMessage(), "\n";
}

try {
    throw new Error('er');
} catch (Throwable $e) {
    echo 'R:', $e->getMessage(), "\n";
}

try {
    throw new Error('bad');
} catch (Exception $e) {
    echo "wrong\n";
} catch (Error $e) {
    echo 'F:', $e->getMessage(), "\n";
}
--EXPECT--
E:ex
R:er
F:bad
