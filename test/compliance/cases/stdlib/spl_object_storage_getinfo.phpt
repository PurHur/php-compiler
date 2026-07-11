--TEST--
SplObjectStorage getInfo/setInfo/getHash (#13142)
--FILE--
<?php
$storage = new SplObjectStorage();
$o1 = new stdClass();
$o2 = new stdClass();
$storage->attach($o1, 'info1');
$storage->attach($o2, 'info2');
echo strlen($storage->getHash($o1)), "\n";
$infos = [];
foreach ($storage as $obj) {
    $infos[] = $storage->getInfo();
    if ($obj === $o1) {
        $storage->setInfo('changed');
    }
}
echo implode(',', $infos), "\n";
echo $storage[$o1], "\n";
--EXPECT--
32
info1,info2
changed
