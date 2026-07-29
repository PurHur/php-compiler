--TEST--
ext calendar jdtounix/easter_date Reflection + named args (VM, issue #24509)
--FILE--
<?php
foreach (['jdtounix', 'easter_date'] as $f) {
    $r = new ReflectionFunction($f);
    $bits = [];
    foreach ($r->getParameters() as $p) {
        $bits[] = $p->getName() . ($p->isOptional() ? '=' : '');
    }
    echo $f, ':', implode(',', $bits),
        ' arity=', $r->getNumberOfParameters(),
        ' req=', $r->getNumberOfRequiredParameters(), PHP_EOL;
}

$jd = gregoriantojd(1, 1, 1970);
echo 'unix_named=', jdtounix(julian_day: $jd), PHP_EOL;
echo 'easter_named=', easter_date(year: 2024), PHP_EOL;
echo 'easter_mode=', easter_date(mode: 0, year: 2024), PHP_EOL;
try {
    jdtounix(jday: $jd);
    echo "legacy jday accepted\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter') ? "legacy jday rejected\n" : "legacy jday other\n";
}
?>
--EXPECT--
jdtounix:julian_day arity=1 req=1
easter_date:year=,mode= arity=2 req=0
unix_named=0
easter_named=1711843200
easter_mode=1711843200
legacy jday rejected
