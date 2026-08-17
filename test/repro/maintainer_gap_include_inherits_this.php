<?php
/**
 * Maintainer gap: include() from an instance method inherits $this (#31903).
 * Zend ZEND_INCLUDE_OR_EVAL copies EX(This); included `return $this->x` is 7.
 * VM/JIT previously: Error "Using $this when not in object context".
 */
error_reporting(E_ALL);

class C
{
    public $x = 7;

    public function f()
    {
        return include __DIR__ . '/maintainer_gap_include_inherits_this_inc.php';
    }
}

$c = new C();
echo $c->f(), "\n";
