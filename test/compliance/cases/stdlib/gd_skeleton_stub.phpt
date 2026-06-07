--TEST--
stdlib gd imagecreate skeleton stub (issue #7407)
--FILE--
<?php
echo 'function_exists=', var_export(function_exists('imagecreate'), true), "\n";
echo 'extension_loaded=', var_export(extension_loaded('gd'), true), "\n";
try {
    imagecreate(1, 1);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
function_exists=true
extension_loaded=true
Error: imagecreate() is not implemented in this compiler build (issue #3496)
