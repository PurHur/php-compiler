--TEST--
AOT: (bool) array uses zend_hash_num_elements ? 1 : 0 (#32455)
--FILE--
<?php
var_dump((bool) []);
var_dump((bool) [1]);
--EXPECT--
bool(false)
bool(true)
