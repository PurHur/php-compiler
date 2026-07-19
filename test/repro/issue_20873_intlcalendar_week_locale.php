<?php
$c = IntlCalendar::createInstance("Europe/Paris", "fr_FR");
foreach (["inDaylightTime","getLocale","isLenient","setLenient","getFirstDayOfWeek","setFirstDayOfWeek","getMinimalDaysInFirstWeek","setMinimalDaysInFirstWeek","getWeekendTransition","getLeastMaximum","getGreatestMinimum"] as $m) {
  echo $m, "=", method_exists($c, $m) ? "yes" : "no", "\n";
}
echo "getKeywordValuesForLocale=", method_exists("IntlCalendar", "getKeywordValuesForLocale") ? "yes" : "no", "\n";
$c->setTime((float) (gmmktime(12, 0, 0, 7, 15, 2024) * 1000));
echo "dst=", (int) $c->inDaylightTime(), "\n";
echo "locale_valid=", $c->getLocale(1), "\n";
echo "lenient=", (int) $c->isLenient(), "\n";
$c->setLenient(false);
echo "lenient2=", (int) $c->isLenient(), "\n";
echo "first=", $c->getFirstDayOfWeek(), "\n";
echo "minDays=", $c->getMinimalDaysInFirstWeek(), "\n";
$vals = IntlCalendar::getKeywordValuesForLocale("calendar", "en_US", true);
$out = [];
foreach ($vals as $v) { $out[] = $v; }
echo "hasGreg=", in_array("gregorian", $out, true) ? "1" : "0", "\n";
echo "leastMax=", $c->getLeastMaximum(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo "greatMin=", $c->getGreatestMinimum(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo "weekendSun=", $c->getWeekendTransition(IntlCalendar::DOW_SUNDAY), "\n";
