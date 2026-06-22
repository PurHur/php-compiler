--TEST--
Language: parenthesized public (private(set)) rejected at compile (#10334, PHP 8.4 zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class Demo {
    public (private(set)) string $name = 'x';
}
echo (new Demo())->name, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Multiple access type modifiers are not allowed
