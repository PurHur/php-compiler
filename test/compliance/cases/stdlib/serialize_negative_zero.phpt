--TEST--
stdlib serialize() preserves negative zero (issue #12837, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

echo serialize(-0.0), "\n";
echo serialize(0.0), "\n";
?>
--EXPECT--
d:-0;
d:0;
