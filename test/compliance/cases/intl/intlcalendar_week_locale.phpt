--TEST--
IntlCalendar week/locale/daylight/keyword APIs (#20873)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlCalendar week/locale withheld until extension_loaded(\'intl\') (#19670/#20873)';
}
?>
--RUNFILE--
intlcalendar_week_locale.php
--EXPECT--
methods=11111
dst=1
locale=fr_FR
first=2 minDays=4
lenient=0
first2=1
greg=1
bounds=28,1
wkSun=86400000
