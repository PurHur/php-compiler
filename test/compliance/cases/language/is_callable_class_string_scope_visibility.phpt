--TEST--
language is_callable() class-string / "Class::method" honors caller scope for instance methods (#23996, Zend/zend_execute_API.c)
--FILE--
<?php
class IsCallableScopeA23996
{
    private function p() {}
    protected function q() {}
    private static function s() {}
    public function pub() {}

    public function r(): void
    {
        echo 'this-priv=', (int) is_callable([$this, 'p']), "\n";
        echo 'self-priv=', (int) is_callable([self::class, 'p']), "\n";
        echo 'str-priv=', (int) is_callable('IsCallableScopeA23996::p'), "\n";
        echo 'self-static-priv=', (int) is_callable([self::class, 's']), "\n";
        echo 'self-pub=', (int) is_callable([self::class, 'pub']), "\n";
    }
}

class IsCallableScopeB23996 extends IsCallableScopeA23996
{
    public function r2(): void
    {
        echo 'A-prot=', (int) is_callable([IsCallableScopeA23996::class, 'q']), "\n";
        echo 'str-prot=', (int) is_callable('IsCallableScopeA23996::q'), "\n";
        echo 'this-prot=', (int) is_callable([$this, 'q']), "\n";
        echo 'child-priv=', (int) is_callable([IsCallableScopeA23996::class, 'p']), "\n";
    }
}

class IsCallableScopeUnrelated23996
{
    public function r(): void
    {
        echo 'unrelated-pub=', (int) is_callable([IsCallableScopeA23996::class, 'pub']), "\n";
    }
}

(new IsCallableScopeA23996)->r();
(new IsCallableScopeB23996)->r2();
(new IsCallableScopeUnrelated23996)->r();
echo 'outside-priv=', (int) is_callable([IsCallableScopeA23996::class, 'p']), "\n";
echo 'outside-prot=', (int) is_callable([IsCallableScopeA23996::class, 'q']), "\n";
echo 'outside-pub=', (int) is_callable([IsCallableScopeA23996::class, 'pub']), "\n";
--EXPECT--
this-priv=1
self-priv=1
str-priv=1
self-static-priv=1
self-pub=1
A-prot=1
str-prot=1
this-prot=1
child-priv=0
unrelated-pub=0
outside-priv=0
outside-prot=0
outside-pub=0
