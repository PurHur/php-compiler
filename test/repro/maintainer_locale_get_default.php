<?php

declare(strict_types=1);

echo 'locale_get_default exists: ';
var_dump(function_exists('locale_get_default'));
echo 'Locale class: ';
var_dump(class_exists('Locale'));

$before = locale_get_default();
echo "default=$before\n";

var_dump(locale_set_default('en_US'));
echo 'after='.locale_get_default()."\n";
echo 'locale_class='.Locale::getDefault()."\n";

var_dump(Locale::setDefault('de_DE'));
echo 'locale_oop='.Locale::getDefault()."\n";

try {
    locale_set_default('!!!');
    echo "invalid_ok\n";
} catch (ValueError $e) {
    echo 'invalid=', $e->getMessage(), "\n";
}
