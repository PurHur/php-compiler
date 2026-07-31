--TEST--
Language: string offset assign object uses __toString first byte / Error (#25794, Zend/zend_execute.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});

class C
{
    public function __toString()
    {
        return 'XY';
    }
}

$s = 'abc';
$s[0] = new C();
echo $s, "\n";

class D
{
}

$t = 'abc';
try {
    $t[0] = new D();
    echo $t, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
W:Only the first byte will be assigned to the string offset
Xbc
Error:Object of class D could not be converted to string
