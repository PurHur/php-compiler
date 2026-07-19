--TEST--
stdlib setcookie/setrawcookie(null) — TypeError forward 8.4 profile JIT (#21003, re-#18659)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['setcookie', 'setrawcookie'] as $f) {
    try {
        $f(null);
        echo "$f: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
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
setcookie(): Argument #1 ($name) must be of type string, null given
setrawcookie(): Argument #1 ($name) must be of type string, null given
empty: setcookie(): Argument #1 ($name) cannot be empty
