<?php
// Issue #11867 — DateTime backing lost after instanceof assign + echo.
$dt = new DateTime('2020-01-01');
$is = $dt instanceof DateTime;
echo $is ? "ok\n" : "no\n";
echo $dt->format('Y-m-d'), "\n";
