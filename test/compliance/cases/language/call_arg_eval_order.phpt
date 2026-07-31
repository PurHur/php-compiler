--TEST--
Language: method-call args evaluate L→R into distinct temps (#25672, Zend/zend_execute.c)
--FILE--
<?php
class C {
    public array $log = [];
    public function next(): int {
        $this->log[] = 'n';
        return count($this->log);
    }
}
$c = new C();
function show($a, $b) { echo "show:$a,$b\n"; }
show($c->next(), $c->next());
echo 'log:', implode('', $c->log), "\n";

$c2 = new C();
$f = function ($a, $b) { echo "f:$a,$b\n"; };
$f($c2->next(), $c2->next());
echo 'log2:', implode('', $c2->log), "\n";

$c3 = new C();
echo 'max:', max($c3->next(), $c3->next()), "\n";
echo 'log3:', implode('', $c3->log), "\n";

// Iterator discard prelude must still advance once (#13901).
$it = new ArrayIterator([1, 2, 3]);
$it->next();
echo 'cur=', var_export($it->current(), true), "\n";
?>
--EXPECT--
show:1,2
log:nn
f:1,2
log2:nn
max:2
log3:nn
cur=2
