--TEST--
Integration: sin, cos, tan with angle conversion
--FILE--
<?php
$rad = deg2rad(45);
echo intval(sin($rad) * 1000), "\n";
echo intval(cos($rad) * 1000), "\n";
echo intval(tan($rad) * 1000), "\n";
echo intval(sin(pi() / 2) * 1000), "\n";
--EXPECT--
707
707
999
1000
