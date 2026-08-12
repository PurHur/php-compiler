--TEST--
Locale::acceptFromHttp(null) AOT TypeError under strict_types (#29914)
--FILE--
<?php
declare(strict_types=1);
try {
    var_export(Locale::acceptFromHttp(null));
    echo "\n";
} catch (TypeError $e) {
    echo (false !== strpos($e->getMessage(), 'null given')) ? "TypeError null\n" : $e->getMessage(), "\n";
}
try {
    var_export(locale_accept_from_http(null));
    echo "\n";
} catch (TypeError $e) {
    echo (false !== strpos($e->getMessage(), 'null given')) ? "TypeError null\n" : $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError null
TypeError null
