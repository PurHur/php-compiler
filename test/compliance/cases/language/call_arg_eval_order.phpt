--TEST--
Method-call args evaluate L→R into distinct temps (#25672)
--FILE--
<?php
// php-cfg hoists MethodCall producers with empty result usages and parseArg-clones them into
// call-arg Temporary->ops. Methods named next/send/rewind/throw were treated as Iterator pointer
// stmts, so sibling producers were skipped: both ARG_SENDs shared one slot and next() ran 3×.

class C {
    public array $log = [];
    public function next(): int {
        $this->log[] = 'n';
        return count($this->log);
    }
}

function show($a, $b) {
    echo "show:$a,$b\n";
}

$c = new C();
show($c->next(), $c->next());
echo 'log:', implode('', $c->log), "\n";

$c2 = new C();
$f = function ($a, $b) {
    echo "clos:$a,$b\n";
};
$f($c2->next(), $c2->next());
echo 'log2:', implode('', $c2->log), "\n";

$c3 = new C();
echo 'max:', max($c3->next(), $c3->next()), "\n";
echo 'log3:', implode('', $c3->log), "\n";

// Iterator pointer stmt before a sibling MethodCall arg must still advance (#13901).
$it = new ArrayIterator([10, 20, 30]);
$it->next();
echo var_export($it->current(), true), "\n";
--EXPECT--
show:1,2
log:nn
clos:1,2
log2:nn
max:2
log3:nn
20
