<?php
// #20771 — IntlBreakIterator preceding/following/isBoundary/getLocale/error getters
$bi = IntlBreakIterator::createWordInstance('en_US');
$bi->setText('Hello world');
echo 'preceding=', method_exists($bi, 'preceding') ? '1' : '0', "\n";
echo 'following=', method_exists($bi, 'following') ? '1' : '0', "\n";
echo 'isBoundary=', method_exists($bi, 'isBoundary') ? '1' : '0', "\n";
echo 'getLocale=', method_exists($bi, 'getLocale') ? '1' : '0', "\n";
echo 'preceding6=', $bi->preceding(6), "\n";
echo 'cur=', $bi->current(), "\n";
echo 'following6=', $bi->following(6), "\n";
echo 'b5=', (int) $bi->isBoundary(5), "\n";
echo 'b6=', (int) $bi->isBoundary(6), "\n";
echo 'b7=', (int) $bi->isBoundary(7), "\n";
echo 'actual=', var_export($bi->getLocale(0), true), "\n";
echo 'valid=', var_export($bi->getLocale(1), true), "\n";
echo 'err=', $bi->getErrorCode(), ':', $bi->getErrorMessage(), "\n";
