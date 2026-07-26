<?php
// AOT compile-only (#23225): sort/rsort Zend named array/flags (no phantom direction).
$a = array(3, 1, 2);
sort(array: $a, flags: SORT_NUMERIC);
$b = array(3, 1, 2);
rsort(array: $b, flags: SORT_NUMERIC);
