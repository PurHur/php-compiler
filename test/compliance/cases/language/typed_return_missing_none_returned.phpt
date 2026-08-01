--TEST--
Language: typed return missing / bare return TypeError none returned (#26486, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

function missing_int(): int
{
}

function bare_int(): int
{
    return;
}

function missing_nullable(): ?string
{
}

function missing_union(): int|string
{
}

function missing_true(): true
{
}

function missing_false(): false
{
}

function missing_null_type(): null
{
}

class C
{
    public function f(): array
    {
    }

    public function s(): static
    {
    }
}

foreach ([
    'missing_int',
    'bare_int',
    'missing_nullable',
    'missing_union',
    'missing_true',
    'missing_false',
    'missing_null_type',
] as $name) {
    try {
        $name();
        echo $name, "=ok\n";
    } catch (Throwable $e) {
        echo $name, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}

try {
    (new C())->f();
    echo "method=ok\n";
} catch (Throwable $e) {
    echo 'method=', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    (new C())->s();
    echo "static=ok\n";
} catch (Throwable $e) {
    echo 'static=', get_class($e), ':', $e->getMessage(), "\n";
}

$fn = function (): object {
};
try {
    $fn();
    echo "closure=ok\n";
} catch (Throwable $e) {
    echo 'closure=', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
missing_int=TypeError:missing_int(): Return value must be of type int, none returned
bare_int=TypeError:bare_int(): Return value must be of type int, none returned
missing_nullable=TypeError:missing_nullable(): Return value must be of type ?string, none returned
missing_union=TypeError:missing_union(): Return value must be of type string|int, none returned
missing_true=TypeError:missing_true(): Return value must be of type true, none returned
missing_false=TypeError:missing_false(): Return value must be of type false, none returned
missing_null_type=TypeError:missing_null_type(): Return value must be of type null, none returned
method=TypeError:C::f(): Return value must be of type array, none returned
static=TypeError:C::s(): Return value must be of type C, none returned
closure=TypeError:{closure}(): Return value must be of type object, none returned
