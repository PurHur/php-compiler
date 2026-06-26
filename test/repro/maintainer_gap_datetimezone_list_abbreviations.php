<?php
declare(strict_types=1);
// Issue #11874 — DateTimeZone::listAbbreviations() / timezone_abbreviations_list() (ext/date/php_date.c).

$procedural = function_exists('timezone_abbreviations_list');
$oop = method_exists('DateTimeZone', 'listAbbreviations');
echo 'procedural=', $procedural ? 'yes' : 'no', "\n";
echo 'oop=', $oop ? 'yes' : 'no', "\n";

if (!$procedural || !$oop) {
    echo "fail\n";
    exit(1);
}

$list = DateTimeZone::listAbbreviations();
echo 'count=', count($list), "\n";
echo 'has_est=', isset($list['est']) ? 'yes' : 'no', "\n";
echo "ok\n";
