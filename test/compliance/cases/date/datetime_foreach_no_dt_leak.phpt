--TEST--
date: foreach(DateTime*/subclass) does not yield __dt_* storage (#23432, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$n = 0;
foreach (new DateTime('2020-01-01 UTC') as $k => $v) {
    echo "DT:$k\n";
    $n++;
}
echo "dt_count=$n\n";

$n = 0;
foreach (new DateTimeImmutable('2020-01-01 UTC') as $k => $v) {
    echo "DTI:$k\n";
    $n++;
}
echo "dti_count=$n\n";

class MyDT23432 extends DateTime
{
    public $x = 1;
}

$keys = [];
foreach (new MyDT23432('2020-01-01 UTC') as $k => $v) {
    $keys[] = $k;
}
echo 'sub_keys=', implode(',', $keys), "\n";
echo str_contains(implode(',', $keys), '__dt_') ? "sub_LEAK\n" : "sub_ok\n";

$gov = get_object_vars(new MyDT23432('2020-01-01 UTC'));
echo 'gov_keys=', implode(',', array_keys($gov)), "\n";
echo isset($gov['__dt_timestamp']) ? "gov_LEAK\n" : "gov_ok\n";
?>
--EXPECT--
dt_count=0
dti_count=0
sub_keys=x
sub_ok
gov_keys=x
gov_ok
