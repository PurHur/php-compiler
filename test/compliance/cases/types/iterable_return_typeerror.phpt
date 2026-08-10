--TEST--
types: :iterable return TypeError says Traversable|array (#29888)
--FILE--
<?php
function expect_iterable(): iterable
{
    return 1;
}
try {
    expect_iterable();
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function ok_arr(): iterable
{
    return [];
}
echo 'arr:', count(ok_arr()), "\n";

function ok_trav(): iterable
{
    return new ArrayIterator([1, 2]);
}
echo 'trav:', iterator_count(ok_trav()), "\n";

function bad_null(): iterable
{
    return null;
}
try {
    bad_null();
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

class C29888
{
    public function method(): iterable
    {
        return false;
    }
}
try {
    (new C29888)->method();
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function gen(): iterable
{
    yield 1;
}
foreach (gen() as $v) {
    echo "y:$v\n";
}
echo "gen-ok\n";
--EXPECT--
expect_iterable(): Return value must be of type Traversable|array, int returned
arr:0
trav:2
bad_null(): Return value must be of type Traversable|array, null returned
C29888::method(): Return value must be of type Traversable|array, false returned
y:1
gen-ok
