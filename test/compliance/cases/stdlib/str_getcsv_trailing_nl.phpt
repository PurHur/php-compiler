--TEST--
stdlib str_getcsv() strips trailing record terminator (#28994)
--FILE--
<?php
foreach (["a,b\n", "a,b\r\n", "a,b", "a,\n", "a,b\r", "a,\"b\nc\"\n", "a,b\n\n"] as $s) {
    echo json_encode(str_getcsv($s)), "\n";
}
--EXPECT--
["a","b"]
["a","b"]
["a","b"]
["a",""]
["a","b"]
["a","b\nc"]
["a","b"]
