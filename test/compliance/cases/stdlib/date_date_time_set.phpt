--TEST--
stdlib date_date_set()/date_time_set() procedural wrappers (ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$dt = date_create('2024-01-15 10:30:00');
date_date_set($dt, 2025, 6, 8);
echo $dt->format('Y-m-d H:i:s'), "\n";
date_time_set($dt, 14, 45, 30);
echo $dt->format('Y-m-d H:i:s'), "\n";
date_time_set($dt, 9, 0);
echo $dt->format('Y-m-d H:i:s'), "\n";
?>
--EXPECT--
2025-06-08 10:30:00
2025-06-08 14:45:30
2025-06-08 09:00:00
