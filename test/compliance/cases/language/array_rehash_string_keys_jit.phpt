--TEST--
language: HashTable string-key write loop JIT (#66, #1959)
--FILE--
<?php
$cfg = ['host' => 'localhost', 'port' => 8080];
$a = [];
for ($i = 0; $i < 20; $i++) {
    $a["key$i"] = $i;
}
echo $cfg['host'], "\n", $a['key19'], "\n";
--EXPECT--
localhost
19
