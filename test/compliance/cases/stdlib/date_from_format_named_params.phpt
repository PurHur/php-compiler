--TEST--
stdlib date_create_from_format / immutable_from_format / date_modify named params (#23289, ext/date/php_date.stub.php)
--FILE--
<?php
date_default_timezone_set('UTC');
foreach (['date_create_from_format', 'date_create_immutable_from_format'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}
$rf = new ReflectionFunction('date_modify');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'date_modify:', implode(',', $names), "\n";
$dt = date_create_from_format(format: 'Y-m-d', datetime: '2024-01-15');
echo $dt instanceof DateTime ? $dt->format('Y-m-d') : 'fail', "\n";
$imm = date_create_immutable_from_format(format: 'Y-m-d', datetime: '2024-02-20');
echo $imm instanceof DateTimeImmutable ? $imm->format('Y-m-d') : 'fail', "\n";
$mod = date_create('2024-01-15');
date_modify(object: $mod, modifier: '+1 day');
echo $mod->format('Y-m-d'), "\n";
?>
--EXPECT--
date_create_from_format:format,datetime,timezone
date_create_immutable_from_format:format,datetime,timezone
date_modify:object,modifier
2024-01-15
2024-02-20
2024-01-16
