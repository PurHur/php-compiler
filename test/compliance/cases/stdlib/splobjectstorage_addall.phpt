--TEST--
SplObjectStorage addAll merges objects and info (#13202)
--FILE--
<?php
$dest = new SplObjectStorage();
$src = new SplObjectStorage();
$o1 = new stdClass();
$o2 = new stdClass();
$src->attach($o1, 'info1');
$src->attach($o2, 'info2');
$dest->addAll($src);
echo $dest->count(), "\n";
echo $dest[$o1], "\n";
echo $dest[$o2], "\n";
$o3 = new stdClass();
$dest->attach($o3, 'old');
$overwrite = new SplObjectStorage();
$overwrite->attach($o3, 'new');
$dest->addAll($overwrite);
echo $dest[$o3], "\n";
--EXPECT--
2
info1
info2
new
