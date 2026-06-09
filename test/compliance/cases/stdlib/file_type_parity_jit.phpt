--TEST--
stdlib file() JIT — numeric-string flags coercion + path TypeError (#4601)
--FILE--
<?php
$tmp = tempnam(sys_get_temp_dir(), 'f');
file_put_contents($tmp, "a\nb\n");
$lines = file($tmp, (string) FILE_IGNORE_NEW_LINES);
echo count($lines), ':', $lines[0], '|', $lines[1], "\n";
try {
    file([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
unlink($tmp);
--EXPECT--
2:a|b
file(): Argument #1 ($filename) must be of type string, array given
