<?php
declare(strict_types=1);
// Issue #15629 — date_sun_info() sunrise/sunset must match Zend (ext/date/php_date.c)

$t = strtotime('2020-06-21');
$info = date_sun_info($t, 51.5, -0.1);
$rise = date_sunrise($t, SUNFUNCS_RET_TIMESTAMP, 51.5, -0.1);
$set = date_sunset($t, SUNFUNCS_RET_TIMESTAMP, 51.5, -0.1);

$ok = true;
if ($info['sunrise'] !== $rise || $info['sunset'] !== $set) {
    fwrite(STDERR, "fail: sun_info sunrise={$info['sunrise']} sunset={$info['sunset']} rise={$rise} set={$set}\n");
    $ok = false;
}
if ($info['sunrise'] !== 1592710857 || $info['sunset'] !== 1592771020) {
    fwrite(STDERR, "fail: zend parity sunrise={$info['sunrise']} sunset={$info['sunset']}\n");
    $ok = false;
}

echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
