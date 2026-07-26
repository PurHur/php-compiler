--TEST--
language: Closure::bindTo(null) on free using-$this closure returns unbound Closure (issue #23387)
--FILE--
<?php
declare(strict_types=1);

$f = function () {
    return isset($this) ? 'yes' : 'no';
};
$b = $f->bindTo(null);
echo is_object($b) ? 'object' : gettype($b);
echo "\n";
if ($b) {
    echo $b(), "\n";
} else {
    echo "null_result\n";
}

class C {
    private int $x = 1;
    public function m(): Closure {
        return function () {
            return $this->x;
        };
    }
}
$bound = (new C())->m();
$u = $bound->bindTo(null);
echo 'bound_unbind=' . gettype($u) . "\n";
--EXPECTF--
PHP Warning:  Cannot unbind $this of closure using $this in %s on line %d
object
no
bound_unbind=NULL
