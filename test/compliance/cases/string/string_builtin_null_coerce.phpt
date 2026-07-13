--TEST--
stdlib string builtins null operand coercion — php-src Z_PARAM_STR (#18611 regression, ext/standard/string.c)
--SKIPIF--
<?php die('skip — compiler VM compliance via VMTest, not Zend CLI'); ?>
--FILE--
<?php
echo var_export(trim(null), true), "\n";
echo var_export(html_entity_decode(null), true), "\n";
echo var_export(explode(',', null), true), "\n";
echo var_export(substr(null, 0), true), "\n";
echo var_export(json_decode(null), true), "\n";
?>
--EXPECT--
''
''
array (
  0 => '',
)
''
NULL
