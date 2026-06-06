--TEST--
stdlib implode() — enum case separator TypeError (#7114, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
enum Sep: string { case Pipe = '|'; }
try {
    implode(Sep::Pipe, ['a', 'b']);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
implode(): Argument #1 ($separator) must be of type array|string, Sep given
