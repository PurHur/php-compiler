--TEST--
date DatePeriod does not advertise Iterator rewind/valid/current/key/next (#22608, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);
$p = new DatePeriod(new DateTime('2020-01-01 UTC'), new DateInterval('P1D'), 2);
foreach (['rewind', 'valid', 'current', 'key', 'next', 'getIterator'] as $m) {
    echo $m, '=', (int) method_exists($p, $m), "\n";
}
$out = [];
foreach ($p as $d) {
    $out[] = $d->format('Y-m-d');
}
echo 'foreach=', implode(',', $out), "\n";
$it = $p->getIterator();
echo 'it=', get_class($it), "\n";
?>
--EXPECT--
rewind=0
valid=0
current=0
key=0
next=0
getIterator=1
foreach=2020-01-01,2020-01-02,2020-01-03
it=InternalIterator
