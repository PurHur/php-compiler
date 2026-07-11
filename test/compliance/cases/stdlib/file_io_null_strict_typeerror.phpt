--TEST--
stdlib file_get_contents/readfile/file_put_contents null under strict_types throws TypeError (#17063, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['file_get_contents', 'readfile'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}

try {
    file_put_contents(null, 'x');
    echo "file_put_contents: uncaught\n";
} catch (TypeError $e) {
    echo 'file_put_contents: ', $e->getMessage(), "\n";
}
--EXPECT--
file_get_contents: file_get_contents(): Argument #1 ($filename) must be of type string, null given
readfile: readfile(): Argument #1 ($filename) must be of type string, null given
file_put_contents: file_put_contents(): Argument #1 ($filename) must be of type string, null given
