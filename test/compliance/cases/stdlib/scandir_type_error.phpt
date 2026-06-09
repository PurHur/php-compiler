--TEST--
stdlib scandir() — TypeError for non-string $directory + numeric-string sorting_order (#4582, ext/standard/dir.c)
--FILE--
<?php
try {
    scandir(123);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$entries = scandir($dir, '1');
echo implode(',', $entries), "\n";
--EXPECT--
TypeError: scandir(): Argument #1 ($directory) must be of type string, int given
readme.txt,b.php,a.php,..,.
