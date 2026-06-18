--TEST--
Return ?? throw expression — non-throwing branch yields value (#9447, zend_compile.c)
--FILE--
<?php
function f(?int $x): int {
    return $x ?? throw new Exception('e');
}
try {
    echo 'null=', f(null), "\n";
} catch (Throwable $e) {
    echo "caught\n";
}
echo 'one=', f(1), "\n";
--EXPECT--
null=caught
one=1
