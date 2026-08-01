--TEST--
Language: nullsafe ?-> under ??/isset stays silent on scalar (FETCH_OBJ_IS, #18026, #26365)
--FILE--
<?php
declare(strict_types=1);
echo (1)?->foo ?? 'nullsafe', "\n";
echo (false)?->x ?? 'ns', "\n";
echo ('x')?->bar ?? 'ok', "\n";
echo ([1])?->n ?? 'arr', "\n";
$n = null;
echo $n?->missing ?? 'ns', "\n";
--EXPECT--
nullsafe
ns
ok
arr
ns
