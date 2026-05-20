--TEST--
foreach on packed list array (VM)
--FILE--
<?php
foreach ([1, 2, 3] as $v) {
    echo $v;
}
echo "\n";
--EXPECT--
123
