<?php
echo "IntlIterator=", class_exists("IntlIterator") ? "yes" : "no", "\n";
$k = IntlCalendar::getKeywordValuesForLocale("calendar", "en_US", true);
echo "type=", get_debug_type($k), "\n";
if (is_object($k)) {
  echo "class=", get_class($k), "\n";
  echo "Iterator=", $k instanceof Iterator ? "yes" : "no", "\n";
}
$n = 0;
foreach ($k as $v) { echo "v=", $v, "\n"; if (++$n >= 3) break; }
$enum = IntlTimeZone::createEnumeration();
echo "enum_class=", is_object($enum) ? get_class($enum) : get_debug_type($enum), "\n";
$ids = iterator_to_array($enum);
echo "enum_gt100=", count($ids) > 100 ? "1" : "0", "\n";
echo "enum_has_utc=", in_array("UTC", $ids, true) ? "1" : "0", "\n";
