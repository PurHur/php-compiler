--TEST--
intl Locale / locale_get_default withheld without extension_loaded('intl') (#19670, re-#16214)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('intl'), "\n";
echo 'fn=', (int) function_exists('locale_get_default'), "\n";
echo 'set_fn=', (int) function_exists('locale_set_default'), "\n";
echo 'class=', (int) class_exists('Locale', false), "\n";
?>
--EXPECT--
loaded=0
fn=0
set_fn=0
class=0
