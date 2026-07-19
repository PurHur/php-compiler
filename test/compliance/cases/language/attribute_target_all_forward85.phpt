--TEST--
Language: Attribute::TARGET_CONSTANT + TARGET_ALL=127 on PROFILE=8.5 (#20727)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.5');
if (!PHPCompiler\CompilerVersion::supportsAttributeTargetConstant()) {
    die('skip Attribute::TARGET_CONSTANT requires PHP_COMPILER_PROFILE=8.5');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
$rc = new ReflectionClass('Attribute');
$allR = $rc->getConstant('TARGET_ALL');
$allD = Attribute::TARGET_ALL;
$has = $rc->hasConstant('TARGET_CONSTANT') ? 1 : 0;
$tcR = $rc->getConstant('TARGET_CONSTANT');
$tcD = Attribute::TARGET_CONSTANT;
$repR = $rc->getConstant('IS_REPEATABLE');
$repD = Attribute::IS_REPEATABLE;
echo "ALL_r=$allR ALL_d=$allD\n";
echo "has_TC=$has\n";
echo "TC_r=$tcR TC_d=$tcD\n";
echo "REP_r=$repR REP_d=$repD\n";
echo 'match=', ($allR === $allD && $tcR === $tcD && $repR === $repD) ? 'yes' : 'NO', "\n";
--EXPECT--
ALL_r=127 ALL_d=127
has_TC=1
TC_r=64 TC_d=64
REP_r=128 REP_d=128
match=yes
