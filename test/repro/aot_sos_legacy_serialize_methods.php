<?php
// #35117 — SplObjectStorage Serializable::serialize()/unserialize() under thin AOT
$empty = new SplObjectStorage();
echo $empty->serialize(), "\n";

$s = new SplObjectStorage();
$s->attach(new stdClass(), 42);
echo $s->serialize(), "\n";

$s2 = new SplObjectStorage();
$s2->unserialize('x:i:1;O:8:"stdClass":0:{},i:42;;m:a:0:{}');
echo $s2->count(), "|";
foreach ($s2 as $obj) {
    echo get_class($obj), "|", $s2->getInfo();
}
echo "\n";

$s3 = new SplObjectStorage();
$s3->unserialize('x:i:1;O:8:"stdClass":0:{},s:4:"info";;m:a:0:{}');
echo $s3->count(), "|";
foreach ($s3 as $obj) {
    echo $s3->getInfo();
}
echo "\n";
