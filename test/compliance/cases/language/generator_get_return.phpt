--TEST--
Generator return value via getReturn() (issue #3350)
--FILE--
<?php
function gen(): Generator {
    yield 1;
    return 99;
}
$g = gen();
foreach ($g as $v) {
    echo $v, "\n";
}
echo $g->getReturn(), "\n";

function bare(): Generator {
    yield 1;
}
$b = bare();
foreach ($b as $v) {
    echo $v, "\n";
}
echo ($b->getReturn() === null ? "null" : "value"), "\n";
--EXPECT--
1
99
1
null
