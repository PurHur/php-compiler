--TEST--
stdlib setcookie null $expires_or_options TypeError under strict_types (#31229, ext/standard/head.c)
--FILE--
<?php
declare(strict_types=1);
try {
    var_export(setcookie('n', 'v', null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
setcookie(): Argument #3 ($expires_or_options) must be of type array|int, null given
