--TEST--
stdlib print_r() — whole-number floats display as integers (#10933)
--FILE--
<?php
echo print_r(1.0, true), "\n";
echo print_r(2.0, true), "\n";
echo print_r(1.5, true), "\n";
--EXPECT--
1
2
1.5
