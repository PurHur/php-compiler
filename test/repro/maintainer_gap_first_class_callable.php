<?php
// Issue #11450 — first-class callable on internal/user functions (Zend/zend_callable.c)
function ulen(string $s): int
{
    return strlen($s);
}

$v = strlen(...)( 'x');
if (1 !== $v) {
    file_put_contents('php://stderr', 'strlen(...)( \'x\') expected 1, got '.var_export($v, true)."\n");
    exit(1);
}

$mapped = array_map(strlen(...), ['a', 'bb']);
if ([1, 2] !== $mapped) {
    file_put_contents('php://stderr', 'array_map(strlen(...)) expected [1,2], got '.var_export($mapped, true)."\n");
    exit(1);
}

$u = ulen(...)( 'abc');
if (3 !== $u) {
    file_put_contents('php://stderr', 'ulen(...)( \'abc\') expected 3, got '.var_export($u, true)."\n");
    exit(1);
}

echo "first_class_callable_ok\n";
