--TEST--
Language: nullsafe ?-> on scalar short-circuits silently under ?? (#18028)
--FILE--
<?php
echo (1)?->foo ?? 'nullsafe', "\n";
echo ('x')?->bar ?? 'ok', "\n";
echo ([1])?->n ?? 'arr', "\n";
--EXPECT--
nullsafe
ok
arr
