--TEST--
stdlib streams null path — ValueError Path cannot be empty (#11016, ext/standard/streamsfuncs.c)
--FILE--
<?php
foreach (['fopen', 'file_get_contents', 'copy', 'readfile', 'file'] as $f) {
    try {
        match ($f) {
            'fopen' => @fopen(null, 'r'),
            'file_get_contents' => @file_get_contents(null),
            'copy' => @copy(null, 'x'),
            'readfile' => @readfile(null),
            'file' => @file(null),
        };
        echo $f, ": miss\n";
    } catch (ValueError $e) {
        echo $f, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
fopen:Path cannot be empty
file_get_contents:Path cannot be empty
copy:Path cannot be empty
readfile:Path cannot be empty
file:Path cannot be empty
