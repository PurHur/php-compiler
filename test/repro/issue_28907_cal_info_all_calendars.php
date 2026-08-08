<?php
// Repro #28907 — cal_info(-1) all-calendars + Reflection default -1
// php-src: ext/calendar/calendar.stub.php / calendar.c

$r = new ReflectionFunction('cal_info');
$default = $r->getParameters()[0]->getDefaultValue();
if ($default !== -1) {
    fwrite(STDERR, "FAIL: Reflection default={$default} want=-1\n");
    exit(1);
}

$all = cal_info(-1);
if (!is_array($all) || count($all) !== 4) {
    fwrite(STDERR, "FAIL: cal_info(-1) count=". (is_array($all) ? count($all) : gettype($all)) ."\n");
    exit(1);
}
$keys = array_keys($all);
if ($keys !== [0, 1, 2, 3]) {
    fwrite(STDERR, "FAIL: keys=".implode(',', $keys)."\n");
    exit(1);
}
if (($all[0]['calname'] ?? null) !== 'Gregorian') {
    fwrite(STDERR, "FAIL: missing Gregorian meta\n");
    exit(1);
}

echo "ok\n";
