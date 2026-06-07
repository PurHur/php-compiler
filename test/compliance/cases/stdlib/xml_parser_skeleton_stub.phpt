--TEST--
stdlib xml_parser_create skeleton stub (issue #7406)
--FILE--
<?php
echo 'function_exists=', var_export(function_exists('xml_parser_create'), true), "\n";
echo 'extension_loaded=', var_export(extension_loaded('xml'), true), "\n";
try {
    xml_parser_create();
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
function_exists=true
extension_loaded=true
Error: xml_parser_create() is not implemented in this compiler build (issue #3494)
