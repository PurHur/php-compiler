--TEST--
Language: instanceof self inside trait binds to using class (#31729, Zend/zend_inheritance.c)
--FILE--
<?php
error_reporting(E_ALL);

trait TInst {
    public function check(object $o): bool
    {
        return $o instanceof self;
    }
}

class CI
{
    use TInst;
}

class CISub extends CI {}

class OtherCI
{
    use TInst;
}

$a = new CI();
echo 'same-class: ', var_export($a->check(new CI()), true), "\n";
echo 'subclass: ', var_export($a->check(new CISub()), true), "\n";
echo 'stdClass: ', var_export($a->check(new stdClass()), true), "\n";
echo 'other-user: ', var_export($a->check(new OtherCI()), true), "\n";
echo 'other-same: ', var_export((new OtherCI())->check(new OtherCI()), true), "\n";

$name = 'self';
echo 'dyn-self: ', var_export($a instanceof $name, true), "\n";
--EXPECT--
same-class: true
subclass: true
stdClass: false
other-user: false
other-same: true
dyn-self: false
