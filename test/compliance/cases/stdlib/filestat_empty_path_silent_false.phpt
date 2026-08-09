--TEST--
stdlib filestat empty path returns false without E_WARNING (#29343)
--FILE--
<?php
$warnings = 0;
set_error_handler(static function () use (&$warnings): bool {
    ++$warnings;
    return true;
});
$fns = [
    'filesize', 'filemtime', 'fileatime', 'filectime',
    'fileowner', 'filegroup', 'fileinode', 'fileperms',
    'filetype', 'stat', 'lstat',
];
foreach ($fns as $f) {
    $r = $f('');
    if (false !== $r) {
        echo $f, " expected false, got ", var_export($r, true), "\n";
    }
}
restore_error_handler();
echo $warnings, "\n";
?>
--EXPECT--
0
