<?php
// #35117 — SplObjectStorage Serializable::serialize()/unserialize() legacy x:/m:

$empty = new SplObjectStorage();
echo 'empty=', var_export($empty->serialize(), true), "\n";

$s = new SplObjectStorage();
$o = new stdClass();
$s->attach($o, 42);
echo 'ser=', var_export($s->serialize(), true), "\n";

$s2 = new SplObjectStorage();
$s2->unserialize('x:i:1;O:8:"stdClass":0:{},i:42;;m:a:0:{}');
echo 'cnt=', $s2->count(), "\n";
foreach ($s2 as $obj) {
    echo 'info=', $s2->getInfo(), "\n";
}
