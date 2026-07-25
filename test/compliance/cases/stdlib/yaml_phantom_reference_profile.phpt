--TEST--
stdlib yaml phantom withhold on reference profile (#6275)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('yaml_parse') ? '1' : '0';
echo function_exists('yaml_parse_url') ? '1' : '0';
echo function_exists('yaml_emit') ? '1' : '0';
echo extension_loaded('yaml') ? '1' : '0';
echo "\n";
?>
--EXPECT--
0000
