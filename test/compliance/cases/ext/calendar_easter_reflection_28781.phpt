--TEST--
ext calendar easter_date/easter_days Reflection ?int year=null (VM, issue #28781)
--FILE--
<?php
foreach (['easter_date', 'easter_days'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f;
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : 'none';
        $def = '';
        if ($p->isOptional()) {
            try {
                $def = '='.var_export($p->getDefaultValue(), true);
            } catch (Throwable $e) {
                $def = '=opt';
            }
        }
        echo " |$t \${$p->getName()}$def";
    }
    echo PHP_EOL;
}
echo 'named=', easter_days(year: 2020, mode: CAL_EASTER_DEFAULT), PHP_EOL;
?>
--EXPECT--
easter_date |?int $year=NULL |int $mode=0
easter_days |?int $year=NULL |int $mode=0
named=22
