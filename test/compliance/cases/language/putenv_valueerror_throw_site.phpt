--TEST--
Language: ValueError from putenv() getFile()/getLine() match user call site (#10679)
--FILE--
<?php
declare(strict_types=1);

try {
    putenv('=invalid');
} catch (ValueError $e) {
    echo $e->getFile() !== '' ? "file_ok\n" : "file_bad\n";
    echo $e->getLine() >= 1 ? "line_ok\n" : "line_bad\n";
}
--EXPECT--
file_ok
line_ok
