--TEST--
AOT M_PI / float math constants through round() (#27249)
--FILE--
<?php
echo round(M_PI, 5), '|', round(pi(), 5), "\n";
echo round(M_E, 5), "\n";
echo round(M_SQRT2, 5), "\n";
--EXPECT--
3.14159|3.14159
2.71828
1.41421
