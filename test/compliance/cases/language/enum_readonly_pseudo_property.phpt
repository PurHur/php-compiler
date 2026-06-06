--TEST--
Language: enum case readonly pseudo-properties name/value — assign/unset must Error (#7155, zend_enum.c)
--FILE--
<?php
declare(strict_types=1);
enum Color: string { case Red = 'red'; }
$c = Color::Red;

try {
    unset($c->name);
    echo "unset_name: OK\n";
} catch (Throwable $e) {
    echo "unset_name: ", get_class($e), ": ", $e->getMessage(), "\n";
}

try {
    $c->name = 'Blue';
    echo "assign_name: OK name=", $c->name, "\n";
} catch (Throwable $e) {
    echo "assign_name: ", get_class($e), ": ", $e->getMessage(), "\n";
}

try {
    unset($c->value);
    echo "unset_value: OK\n";
} catch (Throwable $e) {
    echo "unset_value: ", get_class($e), ": ", $e->getMessage(), "\n";
}

try {
    $c->value = 'blue';
    echo "assign_value: OK value=", $c->value, "\n";
} catch (Throwable $e) {
    echo "assign_value: ", get_class($e), ": ", $e->getMessage(), "\n";
}
--EXPECT--
unset_name: Error: Cannot unset readonly property Color::$name
assign_name: Error: Cannot modify readonly property Color::$name
unset_value: Error: Cannot unset readonly property Color::$value
assign_value: Error: Cannot modify readonly property Color::$value
