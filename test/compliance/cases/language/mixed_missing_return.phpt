--TEST--
Language: :mixed missing / bare return TypeError; void+untyped still null (#26485, Zend/zend_execute.c)
--FILE--
<?php

function missing_mixed(): mixed
{
}

function bare_mixed(): mixed
{
    return;
}

function ok_mixed_null(): mixed
{
    return null;
}

function untyped_ok()
{
}

function void_ok(): void
{
}

class C
{
    public function missing(): mixed
    {
    }
}

foreach ([
    'missing_mixed',
    'bare_mixed',
    'ok_mixed_null',
    'untyped_ok',
    'void_ok',
] as $name) {
    try {
        $r = $name();
        echo $name, '=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}

try {
    (new C())->missing();
    echo "method no throw\n";
} catch (Throwable $e) {
    echo 'method=', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
missing_mixed=TypeError:missing_mixed(): Return value must be of type mixed, none returned
bare_mixed=TypeError:bare_mixed(): Return value must be of type mixed, none returned
ok_mixed_null=NULL
untyped_ok=NULL
void_ok=NULL
method=TypeError:C::missing(): Return value must be of type mixed, none returned
