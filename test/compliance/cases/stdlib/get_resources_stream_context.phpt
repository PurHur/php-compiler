--TEST--
stdlib get_resources() includes default stream-context after fopen (#11104)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
$g = fopen('php://memory', 'r+');
echo count(get_resources()), "\n";
echo count(get_resources('stream')), "\n";
$hasContext = 0;
foreach (get_resources() as $res) {
    if ('stream-context' === get_resource_type($res)) {
        $hasContext = 1;
        break;
    }
}
echo $hasContext, "\n";
--EXPECT--
6
5
1
