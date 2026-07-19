<?php
// #20771 — IntlBreakIterator preceding/following/isBoundary/getLocale/error getters
$bi = IntlBreakIterator::createWordInstance('en_US');
$bi->setText('Hello world');
foreach (['preceding', 'following', 'isBoundary', 'getLocale', 'getErrorCode', 'getErrorMessage'] as $m) {
    echo $m, '=', method_exists($bi, $m) ? '1' : '0', "\n";
}
echo 'preceding6=', $bi->preceding(6), "\n";
echo 'current_after_preceding=', $bi->current(), "\n";
echo 'following6=', $bi->following(6), "\n";
echo 'isBoundary5=', (int) $bi->isBoundary(5), "\n";
echo 'isBoundary6=', (int) $bi->isBoundary(6), "\n";
echo 'isBoundary7=', (int) $bi->isBoundary(7), "\n";
// ULOC_ACTUAL_LOCALE=0 / ULOC_VALID_LOCALE=1 (Locale::ACTUAL_LOCALE / VALID_LOCALE)
echo 'locale_actual=', var_export($bi->getLocale(0), true), "\n";
echo 'locale_valid=', var_export($bi->getLocale(1), true), "\n";
echo 'err=', $bi->getErrorCode(), ':', $bi->getErrorMessage(), "\n";
