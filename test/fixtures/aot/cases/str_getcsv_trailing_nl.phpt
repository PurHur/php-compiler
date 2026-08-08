--TEST--
AOT: str_getcsv() strips trailing record terminator (#28994)
--FILE--
<?php
foreach (["a,b\n", "a,b\r\n", "a,\n", "a,\"b\nc\"\n"] as $s) {
    echo json_encode(str_getcsv($s)), "\n";
}
--EXPECT--
["a","b"]
["a","b"]
["a",""]
["a","b\nc"]
