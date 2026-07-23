--TEST--
stdlib Locale::getDefault() maps C/C.UTF-8 idle env to en_US_POSIX (#22578, ext/intl/locale)
--ENV--
LANG=C.UTF-8
LC_ALL=
--FILE--
<?php
declare(strict_types=1);

$ini = ini_get('intl.default_locale');
// Host Zend: ''; VM may report false until intl.default_locale is registered in VmIni.
echo 'ini_idle=', (int) ($ini === '' || $ini === false), "\n";
echo 'proc=', locale_get_default(), "\n";
echo 'oop=', Locale::getDefault(), "\n";
--EXPECT--
ini_idle=1
proc=en_US_POSIX
oop=en_US_POSIX
