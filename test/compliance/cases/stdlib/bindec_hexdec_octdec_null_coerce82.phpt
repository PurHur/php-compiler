--TEST--
stdlib bindec()/hexdec()/octdec() null still coerces on 8.2 profile (#20658, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo bindec(null), ',', hexdec(null), ',', octdec(null), "\n";
?>
--EXPECT--
0,0,0
