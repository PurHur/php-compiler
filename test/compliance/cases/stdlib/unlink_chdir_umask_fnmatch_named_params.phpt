--TEST--
unlink/chdir/umask/fnmatch named args (VM, issue #23461)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_unlink_named_' . getmypid();
file_put_contents($path, 'x');
var_export(unlink(filename: $path));
echo "\n";
$cwd = getcwd();
var_export(chdir(directory: sys_get_temp_dir()));
echo "\n";
chdir($cwd);
$prev = umask(mask: 0022);
var_export(is_int($prev));
echo "\n";
umask($prev);
var_export(fnmatch(pattern: 'a*', filename: 'abc'));
echo "\n";
foreach (['unlink', 'chdir', 'umask', 'fnmatch'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}
--EXPECT--
true
true
true
true
unlink:filename,context
chdir:directory
umask:mask
fnmatch:pattern,filename,flags
