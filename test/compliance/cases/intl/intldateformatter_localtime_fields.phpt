--TEST--
IntlDateFormatter::localtime ICU tm_yday + unset clock from now (#25228)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlDateFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = new IntlDateFormatter('en_US', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'yyyy-MM-dd');

$before = time();
$off = 0;
$r = $f->localtime('2024-07-15', $off);
$after = time();
echo 'jul15_yday=', $r['tm_yday'], "\n";
echo 'jul15_md=', $r['tm_mday'], ',', $r['tm_mon'], ',', $r['tm_year'], "\n";
echo 'offset=', $off, "\n";

$got = sprintf('%02d:%02d:%02d', $r['tm_hour'], $r['tm_min'], $r['tm_sec']);
$clockOk = false;
for ($t = $before; $t <= $after + 1; $t++) {
    if (gmdate('H:i:s', $t) === $got) {
        $clockOk = true;
        break;
    }
}
echo 'clock_from_now=', $clockOk ? 'yes' : 'no', "\n";

$off = 0;
$r2 = $f->localtime('2024-01-01', $off);
echo 'jan1_yday=', $r2['tm_yday'], "\n";

$f2 = new IntlDateFormatter('en_US', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'yyyy-MM-dd HH:mm:ss');
$off = 0;
$r3 = $f2->localtime('2024-07-15 00:00:00', $off);
echo 'explicit_midnight=', $r3['tm_yday'], ',', $r3['tm_hour'], ',', $r3['tm_min'], ',', $r3['tm_sec'], "\n";
?>
--EXPECT--
jul15_yday=197
jul15_md=15,6,124
offset=10
clock_from_now=yes
jan1_yday=1
explicit_midnight=197,0,0,0
