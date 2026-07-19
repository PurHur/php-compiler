<?php
$c = IntlCalendar::createInstance("Europe/Paris", "fr_FR");
echo "methods=", (int) method_exists($c, "inDaylightTime"), (int) method_exists($c, "getLocale"), (int) method_exists($c, "isLenient"), (int) method_exists($c, "getFirstDayOfWeek"), (int) method_exists("IntlCalendar", "getKeywordValuesForLocale"), "\n";
$c->setTime((float) (gmmktime(12, 0, 0, 7, 15, 2024) * 1000));
echo "dst=", (int) $c->inDaylightTime(), "\n";
echo "locale=", $c->getLocale(1), "\n";
echo "first=", $c->getFirstDayOfWeek(), " minDays=", $c->getMinimalDaysInFirstWeek(), "\n";
$c->setLenient(false);
echo "lenient=", (int) $c->isLenient(), "\n";
$c->setFirstDayOfWeek(IntlCalendar::DOW_SUNDAY);
echo "first2=", $c->getFirstDayOfWeek(), "\n";
$vals = IntlCalendar::getKeywordValuesForLocale("calendar", "en_US", true);
$has = false;
foreach ($vals as $v) { if ($v === "gregorian") { $has = true; break; } }
echo "greg=", $has ? "1" : "0", "\n";
echo "bounds=", $c->getLeastMaximum(IntlCalendar::FIELD_DAY_OF_MONTH), ",", $c->getGreatestMinimum(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo "wkSun=", $c->getWeekendTransition(IntlCalendar::DOW_SUNDAY), "\n";
