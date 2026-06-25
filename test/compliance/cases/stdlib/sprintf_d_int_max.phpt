--TEST--
sprintf() %d PHP_INT_MAX / PHP_INT_MIN (issue #11388)
--FILE--
<?php
declare(strict_types=1);
echo sprintf('%d', PHP_INT_MAX), "\n";
echo sprintf('%d', PHP_INT_MIN), "\n";
printf("%d\n", PHP_INT_MAX);
--EXPECT--
9223372036854775807
-9223372036854775808
9223372036854775807
