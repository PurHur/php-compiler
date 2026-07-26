--TEST--
stdlib mktime/gmmktime hour/minute/second/month/day/year named parameters (#23275, ext/date/php_date.stub.php)
--FILE--
<?php
date_default_timezone_set('UTC');
foreach (['mktime', 'gmmktime'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}
echo mktime(hour: 12, minute: 0, second: 0, month: 1, day: 1, year: 2020), "\n";
echo gmmktime(hour: 12, minute: 0, second: 0, month: 1, day: 1, year: 2020), "\n";
?>
--EXPECT--
mktime:hour,minute,second,month,day,year
gmmktime:hour,minute,second,month,day,year
1577880000
1577880000
