--TEST--
AOT: json_encode→json_decode runtime string roundtrip (#24137, ext/json/php_json.c)
--FILE--
<?php
$j = json_encode(['a' => 1]);
$r = json_decode($j, true);
echo $r['a'], "\n";
?>
--EXPECT--
1
