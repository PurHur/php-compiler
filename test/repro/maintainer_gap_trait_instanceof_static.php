<?php
/**
 * Maintainer gap: instanceof static inside trait method.
 * Zend: static binds to the late-bound class of $this
 * VM: always false
 *
 * Sibling: instanceof self — #31729
 */
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

class BInstStatic extends AInstStatic
{
}

$a = new AInstStatic();
$b = new BInstStatic();

echo 'A vs A: ' . var_export($a->check($a), true) . "\n";
echo 'B vs B: ' . var_export($b->check($b), true) . "\n";
echo 'B vs A: ' . var_export($b->check($a), true) . "\n";
