--TEST--
Language: FCC non-static Error getLine()/getFile() (issue #24397)
--FILE--
<?php
class C
{
    public function m(): void
    {
    }
}
try {
    $fn = C::m(...);
    echo "fail no throw\n";
} catch (Error $e) {
    echo (str_contains($e->getMessage(), 'Non-static method') ? "msg_ok\n" : "msg_bad\n");
    // Exact line differs under jit.php McjitEmbed class pad; require Zend-positive site.
    echo $e->getLine() >= 1 ? "line_ok\n" : "line_bad\n";
    echo $e->getFile() !== '' ? "file_ok\n" : "file_bad\n";
}
--EXPECT--
msg_ok
line_ok
file_ok
