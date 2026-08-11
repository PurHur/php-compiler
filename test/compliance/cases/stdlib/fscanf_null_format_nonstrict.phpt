--TEST--
stdlib fscanf(null) $format soft-null without strict_types (#30236, ext/standard/file.c)
--FILE--
<?php
$f = fopen('php://memory', 'r');
try {
    var_export(fscanf($f, null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
fclose($f);
--EXPECT--
false NO_THROW
