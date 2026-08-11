--TEST--
AOT: fscanf(null) $format TypeError under strict_types (#30236, ext/standard/file.c Z_PARAM_STR)
--FILE--
<?php
declare(strict_types=1);
$f = fopen('php://memory', 'r');
try {
    var_export(fscanf($f, null));
    echo " NO_THROW\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
fclose($f);
?>
--EXPECT--
fscanf(): Argument #2 ($format) must be of type string, null given
