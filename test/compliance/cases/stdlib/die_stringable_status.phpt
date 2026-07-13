--TEST--
stdlib die() Stringable object status echoes __toString() (#18469, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$obj = new class {
    public function __toString(): string
    {
        return 'bye';
    }
};

die($obj);
?>
--EXPECT--
bye
--EXPECT_EXIT--
0
