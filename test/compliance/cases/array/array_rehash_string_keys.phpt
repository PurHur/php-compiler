--TEST--
array: HashTable rehash with 20+ string keys (#66, #1956)
--FILE--
<?php
$a = [];
for ($i = 0; $i < 20; $i++) {
    $a["key$i"] = $i;
}
echo $a['key19'], "\n";
--EXPECT--
19
