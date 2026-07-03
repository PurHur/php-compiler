--TEST--
Language: throw expression as ?? left operand — catchable (Zend zend_compile.c #15315)
--FILE--
<?php
class Ex extends Exception
{
    public function __construct(string $m)
    {
        parent::__construct($m);
    }
}
try {
    $x = throw new Ex('coalesce') ?? 1;
    echo "fail\n";
} catch (Ex $e) {
    echo "ok\n";
}

try {
    $y = (throw new Ex('nested') ?? 2) ?? 3;
    echo "fail\n";
} catch (Ex $e) {
    echo "ok\n";
}
?>
--EXPECT--
ok
ok
