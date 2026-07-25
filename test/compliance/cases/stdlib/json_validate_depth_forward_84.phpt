--TEST--
stdlib json_validate() honors $depth like json_decode (issue #23007)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$flat = '{"a":1}';
$nest = '{"a":{"b":1}}';
foreach ([1, 2, 3] as $d) {
    echo "flat$d=", json_validate($flat, $d) ? '1' : '0', ' ', json_last_error_msg(), "\n";
    echo "nest$d=", json_validate($nest, $d) ? '1' : '0', ' ', json_last_error_msg(), "\n";
}
try {
    json_validate('{}', 0);
    echo "depth0=ok\n";
} catch (ValueError $e) {
    echo "depth0=ValueError\n";
}
--EXPECT--
flat1=0 Maximum stack depth exceeded
nest1=0 Maximum stack depth exceeded
flat2=1 No error
nest2=0 Maximum stack depth exceeded
flat3=1 No error
nest3=1 No error
depth0=ValueError
