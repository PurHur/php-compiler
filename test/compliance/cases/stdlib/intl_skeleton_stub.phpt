--TEST--
stdlib intl Collator/IntlDateFormatter skeleton stubs (issue #5925)
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
    IntlDateFormatter::create('en_US', 0, 0);
    echo "formatter_no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
collator=true
formatter=true
Error: Collator::create() is not implemented in this compiler build (issue #5747)
Error: IntlDateFormatter::create() is not implemented in this compiler build (issue #5201)
