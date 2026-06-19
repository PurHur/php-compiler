--TEST--
ext/date: date_modify month-end agrees VM and JIT (#8770, VmDateTimeNative SSOT)
--FILE--
<?php
$d = new DateTime('2024-01-31', new DateTimeZone('UTC'));
$d->modify('+1 month');
echo $d->format('Y-m-d'), "\n";
--EXPECT--
2024-03-02
