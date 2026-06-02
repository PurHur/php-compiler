--TEST--
Language: unset() on typed property — read throws Error (#4240)
--FILE--
<?php
declare(strict_types=1);

class T {
    public int $i = 0;
}

$t = new T();
unset($t->i);
var_dump(isset($t->i));
var_dump(property_exists($t, 'i'));
try {
    echo $t->i;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
bool(false)
bool(true)
Error: Typed property T::$i must not be accessed before initialization

