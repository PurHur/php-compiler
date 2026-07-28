--TEST--
AOT: foreach over Generator appending to array keeps every yield (#24145)
--FILE--
<?php
function g() {
    yield 1;
    yield 2;
}
$x = [];
foreach (g() as $v) {
    $x[] = $v;
}
echo implode(',', $x), "\n";
--EXPECT--
1,2
