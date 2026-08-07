--TEST--
AOT: foreach over kept Generator + getReturn matches Zend (#28624)
--FILE--
<?php
function gen() {
    yield 1;
    yield 2;
    return 9;
}
$g = gen();
foreach ($g as $v) {
    echo $v, ",";
}
echo "ret=", $g->getReturn(), "\n";
--EXPECT--
1,2,ret=9
--EXPECT_EXIT--
0
