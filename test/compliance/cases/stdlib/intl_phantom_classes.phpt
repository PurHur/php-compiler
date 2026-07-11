--TEST--
stdlib intl OOP classes withheld without ext/intl — no phantom class_exists (#12115, ext/intl/php_intl.c)
--FILE--
<?php
echo 'intl_loaded=', (int) extension_loaded('intl'), "\n";
echo 'collator=', (int) class_exists('Collator', false), "\n";
echo 'formatter=', (int) class_exists('IntlDateFormatter', false), "\n";
try {
    Collator::create('en_US');
    echo "collator_no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    IntlDateFormatter::create('en_US', 0, 0);
    echo "formatter_no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
intl_loaded=0
collator=0
formatter=0
Error: Class "Collator" not found
Error: Class "IntlDateFormatter" not found
