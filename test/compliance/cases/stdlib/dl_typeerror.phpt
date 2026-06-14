--TEST--
stdlib dl() — TypeError for non-string extension_filename (issue #3591, php-src-strict)
--FILE--
<?php
try {
    dl([]);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: dl(): Argument #1 ($extension_filename) must be of type string, array given
