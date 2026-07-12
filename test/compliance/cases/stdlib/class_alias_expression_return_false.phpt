--TEST--
stdlib class_alias() missing class — inline !== false guard (#17756, ext/standard/basic_functions.c)
--FILE--
<?php
if (false !== class_alias('NoSuchClass17756', 'AliasMissing17756')) {
    echo "fail\n";
    exit(1);
}
echo var_export(class_alias('NoSuchClass17756b', 'AliasMissing17756b'), true), "\n";
if (false !== class_alias('NoSuchClass17756c', 'AliasMissing17756c')) {
    echo "fail\n";
    exit(1);
}
echo "ok\n";
?>
--EXPECT--
false
ok
