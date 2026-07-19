<?php
/**
 * #21003 — setcookie(null)/setrawcookie(null) TypeError under PHP_COMPILER_PROFILE=8.4 (re-#18659).
 * php-src: ext/standard/head.c — PHP_FUNCTION(setcookie)/setrawcookie / Z_PARAM_STR $name
 */
foreach (['setcookie', 'setrawcookie'] as $f) {
    try {
        var_export($f(null));
        echo " COERCED\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try {
    setcookie('');
    echo "empty: uncaught\n";
} catch (ValueError $e) {
    echo 'empty: ', $e->getMessage(), "\n";
}
