--TEST--
stdlib spl_autoload() default include_path loader (#4256, ext/spl/php_spl.c)
--FILE--
<?php
declare(strict_types=1);

$dir = sys_get_temp_dir().'/phpc_spl_autoload_'.getmypid();
@mkdir($dir);
$path = $dir.'/defaultloadclass.php';
file_put_contents($path, '<?php class DefaultLoadClass { public function id(): int { return 11; } }');
$prev = set_include_path($dir);
spl_autoload('DefaultLoadClass');
set_include_path($prev);
echo (new DefaultLoadClass())->id(), "\n";
@unlink($path);
@rmdir($dir);
--EXPECT--
11
