--TEST--
Language: instanceof static inside trait late-binds to $this class (#31746, Zend/zend_inheritance.c)
--FILE--
<?php
error_reporting(E_ALL);

trait TInstStatic {
    public function check(object $o): bool
    {
        return $o instanceof static;
    }
}

class AInstStatic
{
    use TInstStatic;
}

class BInstStatic extends AInstStatic {}

class OtherInstStatic
{
    use TInstStatic;
}

$a = new AInstStatic();
$b = new BInstStatic();
echo 'A vs A: ', var_export($a->check($a), true), "\n";
echo 'B vs B: ', var_export($b->check($b), true), "\n";
echo 'B vs A: ', var_export($b->check($a), true), "\n";
echo 'A vs B: ', var_export($a->check($b), true), "\n";
echo 'stdClass: ', var_export($a->check(new stdClass()), true), "\n";
echo 'other-user: ', var_export($a->check(new OtherInstStatic()), true), "\n";
echo 'other-same: ', var_export((new OtherInstStatic())->check(new OtherInstStatic()), true), "\n";

$name = 'static';
echo 'dyn-static: ', var_export($a instanceof $name, true), "\n";
--EXPECT--
A vs A: true
B vs B: true
B vs A: false
A vs B: true
stdClass: false
other-user: false
other-same: true
dyn-static: false
