--TEST--
stdlib scandir() data:// wrapper + missing path errno followup (issue #18418 follow-up)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
    $warnings[] = $errstr;
    return true;
});
@scandir('data://text/plain,hello');
echo 'data='.count($warnings)."\n";
foreach ($warnings as $warning) {
    echo $warning."\n";
}
$warnings = [];
@scandir('/nonexistent/path');
echo 'missing='.count($warnings)."\n";
foreach ($warnings as $warning) {
    echo $warning."\n";
}
--EXPECT--
data=2
scandir(data://text/plain,hello): Failed to open directory: not implemented
scandir(): (errno 0): Success
missing=2
scandir(/nonexistent/path): Failed to open directory: No such file or directory
scandir(): (errno 2): No such file or directory
