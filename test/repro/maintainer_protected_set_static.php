<?php
class Demo {
    protected(set) static string $tag = 'a';
}

echo Demo::$tag, "\n";
try {
    Demo::$tag = 'b';
    echo Demo::$tag, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    echo Demo::$tag, "\n";
}
