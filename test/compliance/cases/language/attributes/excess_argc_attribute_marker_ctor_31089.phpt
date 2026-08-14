--TEST--
language: built-in attribute marker __construct excess argc → ArgumentCountError (#31089)
--FILE--
<?php
try {
    new Attribute(1, 1);
    echo "Attribute ACCEPTED\n";
} catch (Throwable $e) {
    echo 'Attribute ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new AllowDynamicProperties(1);
    echo "AllowDynamicProperties ACCEPTED\n";
} catch (Throwable $e) {
    echo 'AllowDynamicProperties ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new ReturnTypeWillChange(1);
    echo "ReturnTypeWillChange ACCEPTED\n";
} catch (Throwable $e) {
    echo 'ReturnTypeWillChange ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new SensitiveParameter(1);
    echo "SensitiveParameter ACCEPTED\n";
} catch (Throwable $e) {
    echo 'SensitiveParameter ', get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok_attr=', (new Attribute())->flags === Attribute::TARGET_ALL ? "y\n" : "n\n";
echo 'ok_adp=', (new AllowDynamicProperties()) instanceof AllowDynamicProperties ? "y\n" : "n\n";
--EXPECT--
Attribute ArgumentCountError: Attribute::__construct() expects at most 1 argument, 2 given
AllowDynamicProperties ArgumentCountError: AllowDynamicProperties::__construct() expects exactly 0 arguments, 1 given
ReturnTypeWillChange ArgumentCountError: ReturnTypeWillChange::__construct() expects exactly 0 arguments, 1 given
SensitiveParameter ArgumentCountError: SensitiveParameter::__construct() expects exactly 0 arguments, 1 given
ok_attr=y
ok_adp=y
