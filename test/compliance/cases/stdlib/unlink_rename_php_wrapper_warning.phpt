--TEST--
stdlib unlink()/rename() on php:// URIs — wrapper-specific warnings (#18404, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});

$unlinkOk = unlink('php://memory');
$renameOk = rename('php://memory', 'php://temp');

echo 'unlink=', var_export($unlinkOk, true), "\n";
echo 'rename=', var_export($renameOk, true), "\n";
foreach ($warnings as $warning) {
    echo $warning, "\n";
}
?>
--EXPECT--
unlink=false
rename=false
unlink(): PHP does not allow unlinking
rename(): PHP wrapper does not support renaming
