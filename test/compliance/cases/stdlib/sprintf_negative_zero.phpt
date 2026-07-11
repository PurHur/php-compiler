--TEST--
stdlib sprintf() %g/%G preserves negative zero (issue #12838, ext/standard/sprintf.c)
--FILE--
<?php
declare(strict_types=1);

echo sprintf('%g', -0.0), "\n";
echo sprintf('%G', -0.0), "\n";
echo sprintf('%g', 0.0), "\n";
?>
--EXPECT--
-0
-0
0
