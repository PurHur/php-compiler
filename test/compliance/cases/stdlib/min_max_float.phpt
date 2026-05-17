--TEST--
stdlib min() and max() for floats and mixed numeric types
--FILE--
<?php
echo min(1.5, 2.0), "\n";
echo min(3, 2.5), "\n";
echo max(1.5, 2), "\n";
echo max(3, 2.5), "\n";
--EXPECT--
1.5
2.5
2
3
