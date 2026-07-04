--TEST--
intl locale_get_default()/Locale withheld without ext/intl (#16214, ext/intl/php_intl.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('intl'), "\n";
echo 'fn=', (int) function_exists('locale_get_default'), "\n";
echo 'set_fn=', (int) function_exists('locale_set_default'), "\n";
echo 'class=', (int) class_exists('Locale', false), "\n";
--EXPECT--
loaded=0
fn=0
set_fn=0
class=0
