--TEST--
Language: try/finally compile emits no TryCatch::$else warning (#15872)
--FILE--
<?php
function test(): int
{
    try {
        return 1;
    } finally {
        echo 'f';
    }
}
echo test();
--EXPECT--
f1
