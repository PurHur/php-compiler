--TEST--
json_decode() named associative argument (VM, issue #6747)
--FILE--
<?php
var_dump(json_decode('[]', associative: true));
--EXPECT--
array(0) {
}
