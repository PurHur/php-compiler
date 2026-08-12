--TEST--
stdlib setcookie/setrawcookie(null) — DEP + empty name ValueError on 8.4 forward profile (#21233, ext/standard/head.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
foreach (['setcookie', 'setrawcookie'] as $f) {
    try {
        $f(null);
        echo "$f: uncaught\n";
    } catch (TypeError $e) {
        echo 'TypeError: ', $e->getMessage(), "\n";
    } catch (ValueError $e) {
        echo 'ValueError: ', $e->getMessage(), "\n";
    }
}

try {
    setcookie('');
    echo "empty: uncaught\n";
} catch (ValueError $e) {
    echo 'empty: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
ValueError: setcookie(): Argument #1 ($name) cannot be empty
ValueError: setrawcookie(): Argument #1 ($name) cannot be empty
empty: setcookie(): Argument #1 ($name) cannot be empty
