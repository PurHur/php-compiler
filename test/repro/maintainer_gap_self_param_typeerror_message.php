<?php
/**
 * Repro for #29930 — self param TypeError must name the declaring class (Zend).
 *
 * Run: php bin/vm.php test/repro/maintainer_gap_self_param_typeerror_message.php
 */
class A
{
    public function f(self $x)
    {
        echo 'ok';
    }
}
try {
    (new A)->f(1);
    echo "noerr\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
