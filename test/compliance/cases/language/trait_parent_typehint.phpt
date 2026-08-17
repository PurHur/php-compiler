--TEST--
Language: parent typehint inside trait binds to using class parent (#31747, Zend/zend_inheritance.c)
--FILE--
<?php
error_reporting(E_ALL);

class BaseParentHint
{
}

trait TParentHint {
    public function take(parent $o): string
    {
        return get_class($o);
    }
}

class CParentHint extends BaseParentHint
{
    use TParentHint;
}

class UnrelatedParentHint
{
}

$c = new CParentHint();
echo 'base: ', $c->take(new BaseParentHint()), "\n";
echo 'child: ', $c->take($c), "\n";

try {
    $c->take(new UnrelatedParentHint());
    echo "unrelated: ok\n";
} catch (TypeError $e) {
    echo 'unrelated: ', $e->getMessage(), "\n";
}

try {
    $c->take(1);
    echo "int: ok\n";
} catch (TypeError $e) {
    echo 'int: ', $e->getMessage(), "\n";
}
--EXPECTF--
base: BaseParentHint
child: CParentHint
unrelated: CParentHint::take(): Argument #1 ($o) must be of type BaseParentHint, UnrelatedParentHint given, called in %s on line %d
int: CParentHint::take(): Argument #1 ($o) must be of type BaseParentHint, int given, called in %s on line %d
