--TEST--
stdlib glob() GLOB_MARK — directories get trailing slash (#12627, ext/standard/dir.c)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_onlydir_fixture';
$matches = glob($dir . '/*', GLOB_MARK);
$hasMarkedDir = false;
foreach ($matches as $entry) {
    if (str_ends_with($entry, '/')) {
        $hasMarkedDir = true;
        break;
    }
}
var_export($hasMarkedDir);
echo "\n";
$files = glob($dir . '/*.php', GLOB_MARK);
$noSlashOnFile = true;
foreach ($files as $entry) {
    if (str_ends_with($entry, '/')) {
        $noSlashOnFile = false;
        break;
    }
}
var_export($noSlashOnFile);
echo "\n";
--EXPECT--
true
true
