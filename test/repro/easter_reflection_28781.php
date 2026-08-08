<?php
/**
 * #28781 — easter_date/easter_days Reflection ?int $year = null, int $mode = 0.
 */
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
echo 'year_null=', easter_date(year: null) > 0 ? 'ok' : 'bad', PHP_EOL;
echo 'days_null=', easter_days(year: null) >= 0 ? 'ok' : 'bad', PHP_EOL;
echo 'named=', easter_days(year: 2020, mode: CAL_EASTER_DEFAULT), PHP_EOL;
