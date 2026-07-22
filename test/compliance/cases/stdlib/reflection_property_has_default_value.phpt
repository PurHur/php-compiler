--TEST--
ReflectionProperty::hasDefaultValue() / isDefaultValueAvailable() (#11442, #22047)
--FILE--
<?php
declare(strict_types=1);

class RpHasDefaultC {
    public int $withDefault = 7;
    public int $without;
    public $untypedNoInit;
}

$with = new ReflectionProperty(RpHasDefaultC::class, 'withDefault');
$without = new ReflectionProperty(RpHasDefaultC::class, 'without');
$untyped = new ReflectionProperty(RpHasDefaultC::class, 'untypedNoInit');
echo $with->hasDefaultValue() && !$without->hasDefaultValue() ? "has_ok\n" : "has_bad\n";
echo $with->isDefaultValueAvailable() && !$without->isDefaultValueAvailable() ? "avail_ok\n" : "avail_bad\n";
echo $untyped->hasDefaultValue() && null === $untyped->getDefaultValue() ? "untyped_ok\n" : "untyped_bad\n";
--EXPECT--
has_ok
avail_ok
untyped_ok
