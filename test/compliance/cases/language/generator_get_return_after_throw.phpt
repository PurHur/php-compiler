--TEST--
Generator::getReturn() after uncaught throw — Exception not NULL (issue #13027)
--FILE--
<?php
function genThrow(): Generator {
    yield 1;
    throw new Exception('x');
}
$g = genThrow();
$g->rewind();
try { $g->next(); } catch (Exception $e) { echo $e->getMessage(), "\n"; }
try {
    $g->getReturn();
    echo "fail\n";
} catch (Exception $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

function genReturn(): Generator {
    yield 1;
    return 99;
}
$r = genReturn();
foreach ($r as $v) {
    echo $v, "\n";
}
echo $r->getReturn(), "\n";
--EXPECT--
x
Exception: Cannot get return value of a generator that hasn't returned
1
99
