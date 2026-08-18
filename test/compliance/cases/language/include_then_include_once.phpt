--TEST--
language: include then include_once skips re-execution and returns true (#32101, Zend/zend_execute.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc-include-once-' . getmypid() . '-' . bin2hex(random_bytes(4));
@mkdir($dir, 0777, true);
$a = $dir . '/a.php';

file_put_contents($a, "<?php\necho \"RUN\\n\";\nreturn 42;\n");

echo 'include1=';
var_export(include $a);
echo "\n";
echo 'include_once2=';
var_export(include_once $a);
echo "\n";

// Control: include_once twice still matches Zend.
$b = $dir . '/b.php';
file_put_contents($b, "<?php\necho \"RUN2\\n\";\nreturn 7;\n");
echo 'once1=';
var_export(include_once $b);
echo "\n";
echo 'once2=';
var_export(include_once $b);
echo "\n";
?>
--EXPECT--
include1=RUN
42
include_once2=true
once1=RUN2
7
once2=true
