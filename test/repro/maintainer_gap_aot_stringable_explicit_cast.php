<?php

/**
 * #26821 — AOT (string) cast of Stringable must call __toString (matches Zend/VM/JIT).
 */
class A implements Stringable
{
    public function __toString(): string
    {
        return 'S';
    }
}

echo (string) (new A()), "\n";
$a = new A();
echo (string) $a, "\n";
