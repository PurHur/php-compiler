--TEST--
types: :callable return of non-callable throws TypeError (#29887)
--FILE--
<?php
function expect_callable(): callable
{
    return 1;
}
try {
    expect_callable();
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function ok_callable(): callable
{
    return 'strlen';
}
$fn = ok_callable();
echo 'ok:', $fn("ab"), "\n";

function bad_null(): callable
{
    return null;
}
try {
    bad_null();
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

class C29887
{
    public function method(): callable
    {
        return [];
    }
}
try {
    (new C29887)->method();
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
expect_callable(): Return value must be of type callable, int returned
ok:2
bad_null(): Return value must be of type callable, null returned
C29887::method(): Return value must be of type callable, array returned
