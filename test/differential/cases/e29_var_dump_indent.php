<?php
// @differential-skip-aot: var_dump() of arrays needs Runtime->vm, absent in thin standalone AOT (#23540)
// Zend php_var_dump uses level+1 key indent and level+2 recursion (#23726).
var_dump([1, 2]);
var_dump(['a' => ['b' => 1]]);
