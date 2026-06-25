--TEST--
Stdlib: ReflectionAttribute::IS_INSTANCEOF constant + getAttributes filter (#11471)
--FILE--
<?php
if (ReflectionAttribute::IS_INSTANCEOF !== 2) {
    echo "bad constant\n";
    exit(1);
}
$constants = (new ReflectionClass(ReflectionAttribute::class))->getConstants();
echo "has constant: ", (int) isset($constants['IS_INSTANCEOF']), "\n";
echo "constant value: ", $constants['IS_INSTANCEOF'], "\n";

#[Attribute]
class BaseAttr11471 {}
#[Attribute]
class ChildAttr11471 extends BaseAttr11471 {}
#[BaseAttr11471]
#[ChildAttr11471]
class HasAttrs11471 {}

$rc = new ReflectionClass(HasAttrs11471::class);
echo "exact: ", count($rc->getAttributes(BaseAttr11471::class)), "\n";
echo "instanceof: ", count($rc->getAttributes(BaseAttr11471::class, ReflectionAttribute::IS_INSTANCEOF)), "\n";
--EXPECT--
has constant: 1
constant value: 2
exact: 1
instanceof: 2
