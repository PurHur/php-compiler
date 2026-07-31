<?php
/**
 * #25789 — IntlTimeZone::getRawOffset on Etc/Unknown must be silent (Zend/ICU).
 * php-src: ext/intl/timezone/timezone_methods.cpp intltz_get_raw_offset
 */
error_reporting(E_ALL);
$tz = IntlTimeZone::createTimeZone('NoSuch/Zone');
echo get_class($tz), ':', $tz->getID(), "\n";
echo 'raw=', $tz->getRawOffset(), "\n";
echo 'dst=', $tz->getDSTSavings(), "\n";
echo 'use=', $tz->useDaylightTime() ? '1' : '0', "\n";
$raw = $dst = -1;
$ok = $tz->getOffset(0.0, false, $raw, $dst);
echo 'off=', $ok ? '1' : '0', ',raw=', $raw, ',dst=', $dst, "\n";
