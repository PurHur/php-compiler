--TEST--
IntlIterator from getKeywordValuesForLocale + createEnumeration (#20909)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlIterator withheld until extension_loaded(\'intl\') (#19670/#20909)';
}
?>
--FILE--
<?php
echo 'IntlIterator=', class_exists('IntlIterator') ? 'yes' : 'no', "\n";
$k = IntlCalendar::getKeywordValuesForLocale('calendar', 'en_US', true);
echo 'class=', is_object($k) ? get_class($k) : get_debug_type($k), "\n";
echo 'Iterator=', ($k instanceof Iterator) ? 'yes' : 'no', "\n";
$out = [];
foreach ($k as $v) {
    $out[] = $v;
}
echo 'greg=', in_array('gregorian', $out, true) ? '1' : '0', "\n";
echo 'count=', count($out), "\n";

$enum = IntlTimeZone::createEnumeration();
echo 'enum_class=', is_object($enum) ? get_class($enum) : get_debug_type($enum), "\n";
$ids = iterator_to_array($enum);
echo 'enum_gt100=', (int) (count($ids) > 100), "\n";
echo 'enum_has_utc=', (int) in_array('UTC', $ids, true), "\n";

$proc = intlcal_get_keyword_values_for_locale('calendar', 'en_US', true);
echo 'proc_class=', is_object($proc) ? get_class($proc) : get_debug_type($proc), "\n";
?>
--EXPECT--
IntlIterator=yes
class=IntlIterator
Iterator=yes
greg=1
count=1
enum_class=IntlIterator
enum_gt100=1
enum_has_utc=1
proc_class=IntlIterator
