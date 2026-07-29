<?php
/**
 * #24362 — calendar JD helpers Zend stub named args / Reflection names.
 */
foreach (['cal_from_jd', 'easter_date', 'easter_days', 'jdtounix', 'jdtofrench', 'jdtogregorian', 'jdtojulian', 'jdtojewish', 'jddayofweek', 'jdmonthname'] as $f) {
    $r = new ReflectionFunction($f);
    $bits = [];
    foreach ($r->getParameters() as $p) {
        $bits[] = $p->getName() . ($p->isOptional() ? '=' : '');
    }
    echo $f, ':', implode(',', $bits), PHP_EOL;
}

$a = cal_from_jd(julian_day: 2451545, calendar: CAL_GREGORIAN);
echo 'cal_named=', $a['month'], '/', $a['day'], '/', $a['year'], PHP_EOL;
echo 'easter_days=', easter_days(year: 2020, mode: CAL_EASTER_DEFAULT), PHP_EOL;
echo 'jdtounix=', jdtounix(julian_day: 2451545), PHP_EOL;
echo 'jdtojewish=', jdtojewish(julian_day: 2451545), PHP_EOL;
try {
    cal_from_jd(jd: 2451545, calendar: CAL_GREGORIAN);
    echo "legacy jd accepted\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter') ? "legacy jd rejected\n" : "legacy jd other\n";
}
