--TEST--
date: foreach(DateTime*/subclass) no __dt_* leak (JIT, #23432)
--FILE--
<?php
declare(strict_types=1);

$n = 0;
foreach (new DateTime('2020-01-01 UTC') as $k => $v) {
    echo "DT:$k\n";
    $n++;
}
echo "dt_count=$n\n";

class MyDT23432J extends DateTime
{
    public $x = 1;
}

$keys = [];
foreach (new MyDT23432J('2020-01-01 UTC') as $k => $v) {
    $keys[] = $k;
}
echo 'sub_keys=', implode(',', $keys), "\n";
echo str_contains(implode(',', $keys), '__dt_') ? "sub_LEAK\n" : "sub_ok\n";
?>
--EXPECT--
dt_count=0
sub_keys=x
sub_ok
