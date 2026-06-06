--TEST--
Language: PHP 8.4 parenthesized asymmetric visibility public (private(set)) — compile fatal (#6897, #7099)
--FILE--
<?php
declare(strict_types=1);

class Demo {
    public (private(set)) string $name = 'x';
}
echo "compiled\n";
--EXPECT_EXIT--
255
