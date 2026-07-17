--TEST--
stdlib basename()/dirname()/pathinfo() null — coerce on 8.4 forward profile (#19997, ext/standard/basename.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['basename', 'dirname', 'pathinfo'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(basename(null), true), "\n";
echo var_export(dirname(null), true), "\n";
echo is_array(pathinfo(null)) ? "pathinfo: array\n" : "pathinfo: not-array\n";
?>
--EXPECT--
basename: uncaught
dirname: uncaught
pathinfo: uncaught
''
''
pathinfo: array
