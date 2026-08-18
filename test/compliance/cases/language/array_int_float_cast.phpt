--TEST--
Language: (int)/(float) array is zend_hash_num_elements ? 1 : 0 (#32444)
--FILE--
<?php
var_dump((int) []);
var_dump((int) [1, 2]);
var_dump((float) []);
var_dump((float) [7]);
?>
--EXPECT--
int(0)
int(1)
float(0)
float(1)
