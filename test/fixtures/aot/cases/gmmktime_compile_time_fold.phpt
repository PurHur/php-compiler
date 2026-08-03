--TEST--
AOT: gmmktime compile-time UTC fold (#27159)
--FILE--
<?php
echo 'G', gmmktime(0, 0, 0, 1, 1, 1970), "G\n";
echo 'H', gmmktime(12, 0, 0, 1, 1, 2024), "H\n";
--EXPECT--
G0G
H1704110400H
