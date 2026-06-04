--TEST--
Generator yield from inner generator preserves enum cases and getReturn() (issue #5610)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

function gen(): Generator {
    $inner = (function (): Generator {
        yield E::A;
        yield E::B;
        return 'done';
    })();
    $v = yield from $inner;
    return $v;
}

$g = gen();
$names = '';
while ($g->valid()) {
    $names .= ($g->current() instanceof E ? $g->current()->name : 'scalar') . ',';
    $g->next();
}
echo rtrim($names, ','), "\n";
echo $g->getReturn(), "\n";
--EXPECT--
A,B
done
