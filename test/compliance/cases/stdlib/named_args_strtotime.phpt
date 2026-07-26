--TEST--
strtotime named datetime/baseTimestamp arguments (VM, issue #23216)
--FILE--
<?php
$positional = strtotime('2020-01-01');
var_export(strtotime(datetime: '2020-01-01') === $positional);
echo PHP_EOL;
var_export(strtotime(datetime: '2020-01-01', baseTimestamp: 1577836800) === strtotime('2020-01-01', 1577836800));
echo PHP_EOL;
$rf = new ReflectionFunction('strtotime');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
--EXPECT--
true
true
datetime
baseTimestamp
