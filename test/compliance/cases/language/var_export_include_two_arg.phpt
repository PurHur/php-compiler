--TEST--
language: var_export(include $path, true) after @mkdir keeps file return (#25851, Zend/zend_execute.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc-ve-inc-' . getmypid() . '-' . bin2hex(random_bytes(4));
@mkdir($dir, 0777, true);
$scalar = $dir . '/scalar.php';
$array = $dir . '/array.php';
file_put_contents($scalar, "<?php return 7;\n");
file_put_contents($array, "<?php return ['a' => 1];\n");

// Nested include as arg0 of two-arg var_export after @ — must not steal @mkdir result (#25851 / #21938).
$path = $dir . '/ve_' . getmypid() . '.php';
file_put_contents($path, "<?php return 7;\n");
echo var_export(include $path, true), "\n";

$a = include $path;
echo var_export($a, true), "\n";

echo var_export(include $scalar, true), "\n";
echo var_export(include $array, true), "\n";

// Single-arg form (already covered by #21938) — keep green beside two-arg.
echo 'single=';
var_export(include $scalar);
echo "\n";

function id($x) {
    return $x;
}
echo id(include $scalar), "\n";
echo strlen(include $scalar), "\n";

@unlink($path);
@unlink($scalar);
@unlink($array);
@rmdir($dir);
?>
--EXPECT--
7
7
7
array (
  'a' => 1,
)
single=7
7
1
