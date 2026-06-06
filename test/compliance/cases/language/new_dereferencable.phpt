--TEST--
Language: dereferencable `new` without outer parentheses (#6974, PHP 8.4)
--FILE--
<?php
declare(strict_types=1);

class Greeter
{
    public string $tag = 'hi';
    public static string $kind = 'greet';

    public function greet(): string
    {
        return 'hello';
    }

    public static function label(): string
    {
        return 'static';
    }
}

echo new Greeter()->greet(), "\n";
echo new Greeter()->tag, "\n";
echo new Greeter()::label(), "\n";
echo (new Greeter())->greet(), "\n";
--EXPECT--
hello
hi
static
hello
