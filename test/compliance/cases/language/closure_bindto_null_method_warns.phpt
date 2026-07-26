--TEST--
language: Closure::bindTo(null) on method/FCC warns Cannot unbind $this of method (issue #23421)
--FILE--
<?php
declare(strict_types=1);

error_reporting(E_ALL);

class A
{
    public $x = 1;
    public function f()
    {
        return $this->x;
    }
}

$c = Closure::fromCallable([new A(), 'f']);
$r = $c->bindTo(null);
echo 'fromCallable=' . ($r === null ? 'NULL' : 'C') . "\n";

$c2 = (new A())->f(...);
$r2 = $c2->bindTo(null);
echo 'fcc=' . ($r2 === null ? 'NULL' : 'C') . "\n";

$u = (function () {
    return $this->x;
})->bindTo(new A());
$r3 = $u->bindTo(null);
echo 'user=' . ($r3 === null ? 'NULL' : 'C') . "\n";
--EXPECTF--
PHP Warning:  Cannot unbind $this of method in %s on line %d
PHP Warning:  Cannot unbind $this of method in %s on line %d
PHP Warning:  Cannot unbind $this of closure using $this in %s on line %d
fromCallable=NULL
fcc=NULL
user=NULL
