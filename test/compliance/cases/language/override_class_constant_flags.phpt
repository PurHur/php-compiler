--TEST--
Language: Override Attribute flags exclude TARGET_CLASS_CONSTANT (#26253)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::advertisesOverrideAttributeClass()) {
    echo "skip — Override class not advertised on reference profile\n";
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionClass(Override::class);
$a = $r->getAttributes(Attribute::class)[0]->newInstance();
echo $a->flags, "\n";
echo (($a->flags & Attribute::TARGET_METHOD) !== 0) ? "method=1\n" : "method=0\n";
echo (($a->flags & Attribute::TARGET_CLASS_CONSTANT) !== 0) ? "const=1\n" : "const=0\n";
echo (($a->flags & Attribute::TARGET_PROPERTY) !== 0) ? "prop=1\n" : "prop=0\n";
?>
--EXPECT--
4
method=1
const=0
prop=0
