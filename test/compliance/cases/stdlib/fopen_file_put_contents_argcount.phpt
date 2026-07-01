--TEST--
stdlib fopen()/file_put_contents() — ArgumentCountError cites actual argc (#14686, ext/standard/streamsfuncs.c)
--FILE--
<?php
declare(strict_types=1);
try {
    fopen(null);
    echo "fopen:no\n";
} catch (ArgumentCountError $e) {
    echo 'fopen:', $e->getMessage(), "\n";
}
try {
    file_put_contents(null);
    echo "fpc:no\n";
} catch (ArgumentCountError $e) {
    echo 'fpc:', $e->getMessage(), "\n";
}
--EXPECT--
fopen:fopen() expects at least 2 arguments, 1 given
fpc:file_put_contents() expects at least 2 arguments, 1 given
