--TEST--
libxml libxml_disable_entity_loader() — E_DEPRECATED on call (#18106, ext/libxml/libxml.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
@libxml_disable_entity_loader(false);
$last = error_get_last();
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Function libxml_disable_entity_loader() is deprecated') ? 'dep_ok' : 'dep_fail';
echo "\n";
?>
--EXPECT--
8192
dep_ok
