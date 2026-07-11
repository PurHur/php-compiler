--TEST--
Language: nullsafe ?-> on scalar/non-object short-circuits silently (#18026)
--FILE--
<?php
declare(strict_types=1);
echo (1)?->foo ?? 'nullsafe', "\n";
echo (false)?->x ?? 'ns', "\n";
$obj = new stdClass();
echo $obj?->missing ?? 'ns', "\n";
--EXPECT--
nullsafe
ns
ns
