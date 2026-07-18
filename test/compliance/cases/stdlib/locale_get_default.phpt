--TEST--
stdlib locale_get_default()/locale_set_default() and Locale class (#9576, ext/intl/php_intl.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('intl'), "\n";
echo 'fn=', (int) function_exists('locale_get_default'), "\n";
echo 'class=', (int) class_exists('Locale', false), "\n";

var_dump(locale_set_default('en_US'));
echo 'proc=', locale_get_default(), "\n";
echo 'oop=', Locale::getDefault(), "\n";

var_dump(Locale::setDefault('de_DE'));
echo 'oop2=', Locale::getDefault(), "\n";

try {
    locale_set_default('!!!');
    echo "fail\n";
} catch (ValueError $e) {
    echo 'err=', str_contains($e->getMessage(), 'valid locale') ? 'yes' : 'no', "\n";
}
--EXPECT--
loaded=1
fn=1
class=1
bool(true)
proc=en_US
oop=en_US
bool(true)
oop2=de_DE
err=yes
