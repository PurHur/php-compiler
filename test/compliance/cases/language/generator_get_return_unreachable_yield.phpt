--TEST--
Generator return before unreachable yield still returns via getReturn() (issue #3350)
--FILE--
<?php
function gen(): Generator {
    return 99;
    yield 1;
}
$g = gen();
foreach ($g as $v) {
    echo $v, "\n";
}
echo $g->getReturn(), "\n";
--EXPECT--
99
