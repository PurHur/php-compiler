--TEST--
Language: public readonly private(set) modifier orders (#29387, Zend/zend_language_parser.y)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
    die('skip asymmetric visibility + readonly orders require PHP 8.4 forward profile');
}
?>
--FILE--
<?php
declare(strict_types=1);

class Between {
    public readonly private(set) int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$a = new Between(1);
echo $a->x, "\n";
try { $a->x = 2; } catch (Error $e) { echo $e->getMessage(), "\n"; }

class SetThenReadonly {
    public private(set) readonly int $y;
    public function __construct(int $y) { $this->y = $y; }
}
$b = new SetThenReadonly(3);
echo $b->y, "\n";
try { $b->y = 4; } catch (Error $e) { echo $e->getMessage(), "\n"; }

class ReadonlyFirst {
    readonly public private(set) int $z;
    public function __construct(int $z) { $this->z = $z; }
}
$c = new ReadonlyFirst(5);
echo $c->z, "\n";
try { $c->z = 6; } catch (Error $e) { echo $e->getMessage(), "\n"; }
--EXPECT--
1
Cannot modify readonly property Between::$x
3
Cannot modify readonly property SetThenReadonly::$y
5
Cannot modify readonly property ReadonlyFirst::$z
