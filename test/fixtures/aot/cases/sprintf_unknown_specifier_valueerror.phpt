--TEST--
AOT: sprintf('%Z', 1) unknown specifier ValueError (#27826, formatted_print.c)
--FILE--
<?php
try {
    sprintf('%Z', 1);
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
ValueError:Unknown format specifier "Z"
