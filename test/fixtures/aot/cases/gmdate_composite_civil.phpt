--TEST--
AOT: gmdate composite Y-m-d H:i:s and c (#27157)
--FILE--
<?php
echo gmdate('Y-m-d H:i:s', 0), "\n";
echo gmdate('c', 0), "\n";
echo gmdate('Y-m-d H:i:s', 1704110400), "\n";
--EXPECT--
1970-01-01 00:00:00
1970-01-01T00:00:00+00:00
2024-01-01 12:00:00
