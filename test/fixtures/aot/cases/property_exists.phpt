--TEST--
Stdlib: property_exists() AOT (issue #1372)
--FILE--
<?php
class Box {
    public int $x = 1;
}
echo property_exists('Box', 'x') ? '1' : '0';
echo property_exists('Box', 'missing') ? '1' : '0';
echo "\n";
--EXPECT--
10
