--TEST--
Language: object array key via new inside try — TypeError Illegal offset type (#29532)
--FILE--
<?php
error_reporting(E_ALL);

class T {}

class S {
    public function __toString(): string {
        echo "TOSTRING\n";
        return 'k';
    }
}

$a = [];
try {
    $a[new T()] = 1;
    echo "AFTER_PLAIN\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$b = [];
try {
    $b[new S()] = 1;
    echo 'keys=', json_encode(array_keys($b)), "\n";
    echo "AFTER_STR\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$c = [];
$o = new T();
try {
    $c[$o] = 1;
    echo "AFTER_VAR\n";
} catch (Throwable $e) {
    echo 'var ', get_class($e), ': ', $e->getMessage(), "\n";
}

echo "done\n";
--EXPECT--
TypeError: Illegal offset type
TypeError: Illegal offset type
var TypeError: Illegal offset type
done
