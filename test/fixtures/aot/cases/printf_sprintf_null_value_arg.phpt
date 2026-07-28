--TEST--
AOT: printf/sprintf null value arg coerces for %s/%d (#24258, ext/standard/formatted_print.c)
--FILE--
<?php
printf('%s', null);
echo '|';
printf('%d', null);
echo "\n";
echo sprintf('<%s>', null), "\n";
--EXPECT--
|0
<>
