--TEST--
language: inline clone new / (object) cast call operands (#13687, zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

class C {}

function id(object $o): string
{
    return get_class($o);
}

echo id(clone new C), "\n";
echo id((object) ['a' => 1]), "\n";
--EXPECT--
C
stdClass
