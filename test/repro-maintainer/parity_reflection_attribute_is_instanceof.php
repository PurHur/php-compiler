<?php

declare(strict_types=1);

if (!defined('ReflectionAttribute::IS_INSTANCEOF')) {
    echo "FAIL ReflectionAttribute::IS_INSTANCEOF undefined\n";
    exit(1);
}
if (ReflectionAttribute::IS_INSTANCEOF !== 2) {
    echo "FAIL ReflectionAttribute::IS_INSTANCEOF value\n";
    exit(1);
}
$constants = (new ReflectionClass(ReflectionAttribute::class))->getConstants();
if (!isset($constants['IS_INSTANCEOF']) || $constants['IS_INSTANCEOF'] !== 2) {
    echo "FAIL ReflectionAttribute::getConstants()\n";
    exit(1);
}

#[Attribute]
class ParityBaseAttr11471
{
}

#[Attribute]
class ParityChildAttr11471 extends ParityBaseAttr11471
{
}

#[ParityBaseAttr11471]
#[ParityChildAttr11471]
class ParityHasAttrs11471
{
}

$rc = new ReflectionClass(ParityHasAttrs11471::class);
$exact = $rc->getAttributes(ParityBaseAttr11471::class);
$instanceof = $rc->getAttributes(ParityBaseAttr11471::class, ReflectionAttribute::IS_INSTANCEOF);
if (1 !== count($exact) || 2 !== count($instanceof)) {
    echo "FAIL getAttributes IS_INSTANCEOF filter\n";
    exit(1);
}

echo "ok\n";
