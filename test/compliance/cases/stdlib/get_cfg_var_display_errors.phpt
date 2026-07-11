--TEST--
stdlib get_cfg_var('display_errors') under @ matches Zend empty string (#15916, ext/standard/ini.c)
--FILE--
<?php
$v = @get_cfg_var('display_errors');
echo is_string($v) && '' === $v ? "cfg_empty\n" : "cfg_bad\n";
echo var_export(@get_cfg_var('display_errors'), true), "\n";
--EXPECT--
cfg_empty
''
