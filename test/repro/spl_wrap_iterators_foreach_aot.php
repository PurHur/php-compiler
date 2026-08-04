<?php
echo "Parent:";
$p = new ParentIterator(new RecursiveArrayIterator(["a"=>1,"b"=>["c"=>2,"d"=>3]]));
foreach ($p as $k => $v) { echo "$k "; }
echo "\nMulti:";
$m = new MultipleIterator();
$m->attachIterator(new ArrayIterator(["a","b"]), "L");
$m->attachIterator(new ArrayIterator([1,2]), "N");
foreach ($m as $v) { echo implode(":", $v), " "; }
echo "\nTree:";
$t = new RecursiveTreeIterator(new RecursiveArrayIterator(["a"=>["b"=>1,"c"=>2]]));
foreach ($t as $v) { echo $v, "|"; }
echo "\n";
