<?php
class Demo {
    public private(set) static string $name = 'a';
}

echo Demo::$name, "\n";
try {
    Demo::$name = 'b';
    echo Demo::$name, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    echo Demo::$name, "\n";
}
