--TEST--
stdlib implode()/join() — __toString objects concatenate (#9557, ext/standard/string.c)
--FILE--
<?php
class C
{
    public function __toString(): string
    {
        return 'x';
    }
}
var_export(implode('', [new C(), new C()]));
echo "\n";
var_export(join(',', [new C(), new C()]));
echo "\n";
class Plain
{
}
try {
    implode('', [new Plain()]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
'xx'
'x,x'
Error: Object of class Plain could not be converted to string
