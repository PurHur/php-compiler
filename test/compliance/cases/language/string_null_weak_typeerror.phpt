--TEST--
Language: weak-mode non-nullable string rejects null (params/return/property/variadic) (#19695, Zend/zend_execute.c)
--FILE--
<?php
function stripCallSite(string $msg): string
{
    $pos = strpos($msg, ', called in ');
    return false === $pos ? $msg : substr($msg, 0, $pos);
}

function f(string $s): string
{
    return $s;
}

try {
    f(null);
    echo "param_NO_THROW\n";
} catch (TypeError $e) {
    echo 'param: ', stripCallSite($e->getMessage()), "\n";
}

echo 'int_ok=', var_export(f(1), true), "\n";
echo 'bool_ok=', var_export(f(true), true), "\n";
echo 'float_ok=', var_export(f(1.5), true), "\n";

class C
{
    public string $s = 'x';
}

$c = new C();
try {
    $c->s = null;
    echo "prop_NO_THROW\n";
} catch (TypeError $e) {
    echo 'prop: ', $e->getMessage(), "\n";
}

function g(): string
{
    return null;
}

try {
    g();
    echo "ret_NO_THROW\n";
} catch (TypeError $e) {
    echo 'ret: ', $e->getMessage(), "\n";
}

function h(string ...$s): int
{
    return count($s);
}

try {
    h('a', null);
    echo "var_NO_THROW\n";
} catch (TypeError $e) {
    echo 'var: ', stripCallSite($e->getMessage()), "\n";
}

class M
{
    public function __construct(string $s = '')
    {
    }

    public function m(string $s): string
    {
        return $s;
    }
}

try {
    (new M('x'))->m(null);
    echo "method_NO_THROW\n";
} catch (TypeError $e) {
    echo 'method: ', stripCallSite($e->getMessage()), "\n";
}

try {
    new M(null);
    echo "ctor_NO_THROW\n";
} catch (TypeError $e) {
    echo 'ctor: ', stripCallSite($e->getMessage()), "\n";
}
?>
--EXPECT--
param: f(): Argument #1 ($s) must be of type string, null given
int_ok='1'
bool_ok='1'
float_ok='1.5'
prop: Cannot assign null to property C::$s of type string
ret: g(): Return value must be of type string, null returned
var: h(): Argument #2 must be of type string, null given
method: M::m(): Argument #1 ($s) must be of type string, null given
ctor: M::__construct(): Argument #1 ($s) must be of type string, null given
