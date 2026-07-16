--TEST--
stdlib intl Collator + IntlDateFormatter withheld without ext/intl (#19670, #12115)
--FILE--
<?php
echo 'collator=', var_export(class_exists('Collator', false), true), "\n";
echo 'formatter=', var_export(class_exists('IntlDateFormatter', false), true), "\n";
try {
    Collator::create('en_US');
    echo "collator_no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    IntlDateFormatter::create('en_US', 0, 0, 'UTC', 1, 'yyyy-MM-dd');
    echo "formatter_no_throw\n";
} catch (Throwable $e) {
    echo 'formatter_err=', get_class($e), "\n";
}
?>
--EXPECT--
collator=false
formatter=false
Error: Class "Collator" not found
formatter_err=Error
