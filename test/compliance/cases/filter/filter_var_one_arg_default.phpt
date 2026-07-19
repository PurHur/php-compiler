--TEST--
filter_var() one-arg defaults to FILTER_DEFAULT; wrong argc is ArgumentCountError (#20988)
--FILE--
<?php
declare(strict_types=1);

var_export(filter_var('1'));
echo "\n";
var_export(filter_var('1', FILTER_DEFAULT));
echo "\n";

try {
    // @phpstan-ignore-next-line intentional zero-arg call
    filter_var();
    echo "zero-arg OK\n";
} catch (ArgumentCountError $e) {
    echo 'zero: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'zero unexpected: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    // @phpstan-ignore-next-line intentional four-arg call
    filter_var('1', FILTER_DEFAULT, 0, 0);
    echo "four-arg OK\n";
} catch (ArgumentCountError $e) {
    echo 'four: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'four unexpected: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
'1'
'1'
zero: filter_var() expects at least 1 argument, 0 given
four: filter_var() expects at most 3 arguments, 4 given
