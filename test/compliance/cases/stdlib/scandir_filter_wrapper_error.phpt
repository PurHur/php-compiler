--TEST--
stdlib scandir()/opendir() php://filter wrapper errors (issue #18418)
--FILE--
<?php
$path = 'php://filter/read=string.rot13/resource=/etc/passwd';
$warnings = [];
set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
    $warnings[] = $errstr;
    return true;
});
@scandir($path);
echo 'scandir_warnings='.count($warnings)."\n";
foreach ($warnings as $warning) {
    echo $warning."\n";
}
$warnings = [];
@opendir($path);
echo 'opendir_warnings='.count($warnings)."\n";
foreach ($warnings as $warning) {
    echo $warning."\n";
}
--EXPECT--
scandir_warnings=2
scandir(php://filter/read=string.rot13/resource=/etc/passwd): Failed to open directory: not implemented
scandir(): (errno 0): Success
opendir_warnings=1
opendir(php://filter/read=string.rot13/resource=/etc/passwd): Failed to open directory: not implemented
