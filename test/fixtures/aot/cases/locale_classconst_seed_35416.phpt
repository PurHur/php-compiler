--TEST--
Locale::ACTUAL_LOCALE ClassConstFetch seeds for thin AOT (#35416)
--FILE--
<?php
echo 'ACTUAL_LOCALE=', Locale::ACTUAL_LOCALE, "\n";
echo 'VALID_LOCALE=', Locale::VALID_LOCALE, "\n";
--EXPECT--
ACTUAL_LOCALE=0
VALID_LOCALE=1
