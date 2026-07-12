--TEST--
stdlib scandir()/opendir() on php://filter — not implemented wrapper diagnostics (#18418, ext/standard/dir.c)
--FILE--
<?php
declare(strict_types=1);

$path = 'php://filter/read=string.rot13/resource=data://text/plain,test';
$warnings = [];
set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});

$scandirOk = scandir($path);
$opendirOk = opendir($path);

echo 'scandir=', var_export($scandirOk, true), "\n";
echo 'opendir=', var_export($opendirOk, true), "\n";
foreach ($warnings as $warning) {
    echo $warning, "\n";
}
?>
--EXPECT--
scandir=false
opendir=false
scandir(php://filter/read=string.rot13/resource=data://text/plain,test): Failed to open directory: not implemented
scandir(): (errno 0): Success
opendir(php://filter/read=string.rot13/resource=data://text/plain,test): Failed to open directory: not implemented
