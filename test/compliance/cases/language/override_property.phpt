--TEST--
Language: #[\Override] on properties — parent override compiles (#9822, #25138 PHP 8.5+)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsOverridePropertyTarget()) {
    echo "skip — #[\\Override] on properties requires PROFILE≥8.5 (#25138)\n";
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

class Base {
    public int $x = 1;
}

class Child extends Base {
    #[\Override]
    public int $x = 2;
}

echo (new Child())->x, "\n";
?>
--EXPECT--
2
