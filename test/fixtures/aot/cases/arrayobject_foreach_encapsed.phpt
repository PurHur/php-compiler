--TEST--
AOT ArrayObject foreach with encapsed echo (#28625)
--FILE--
<?php
$o = new ArrayObject(['a' => 1, 'b' => 2]);
foreach ($o as $k => $v) {
    echo "$k=$v;";
}
echo "\n";
--EXPECT--
a=1;b=2;
