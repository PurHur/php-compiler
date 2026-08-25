<?php
// Zend php_var_dump uses level+1 key indent and level+2 recursion (#23726).
var_dump([1, 2]);
var_dump(['a' => ['b' => 1]]);
