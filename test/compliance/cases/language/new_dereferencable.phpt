--TEST--
Language: dereferencable `new` without outer parentheses on forward profile (#6974, #19684, PHP 8.4)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
    die('skip requires PHP 8.4+ dereferencable new forward profile');
}
if (PHP_VERSION_ID < 80400) {
    die('skip dereferencable new requires PHP 8.4+ Zend for native PHPT');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
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
echo new class {
    public function hello(): string
    {
        return 'anon';
    }
}->hello(), "\n";
--EXPECT--
hello
hi
static
hello
anon
