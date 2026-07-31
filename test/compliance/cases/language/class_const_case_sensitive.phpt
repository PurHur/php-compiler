--TEST--
Language: class constants are case-sensitive (Zend/zend_constants.c, #25910)
--FILE--
<?php
class ClassConstCase
{
    public const X = 1;
    private const SECRET = 2;
}

try {
    echo ClassConstCase::x;
    echo " BUG wrong case resolved\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

echo ClassConstCase::X, "\n";

try {
    echo ClassConstCase::secret;
    echo " BUG private wrong-case leaked\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

echo ClassConstCase::class, "\n";
echo ClassConstCase::CLASS, "\n";
--EXPECT--
Undefined constant ClassConstCase::x
1
Undefined constant ClassConstCase::secret
ClassConstCase
ClassConstCase
