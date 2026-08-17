<?php
/**
 * Maintainer gap: instanceof self inside trait method.
 * Zend: self binds to the using class → true for same-class instance
 * VM: always false
 */
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

$a = new CI();
$b = new CI();
$o = new stdClass();

echo 'same-class: ' . var_export($a->check($b), true) . "\n";
echo 'stdClass: ' . var_export($a->check($o), true) . "\n";
