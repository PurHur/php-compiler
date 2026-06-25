--TEST--
ReflectionProperty::getDefaultValue() returns declared default (#11239)
--FILE--
<?php
declare(strict_types=1);

class RpGetDefaultC {
    public int $count = 5;
    public int $unset;
}

$prop = new ReflectionProperty(RpGetDefaultC::class, 'count');
$none = new ReflectionProperty(RpGetDefaultC::class, 'unset');
echo 5 === $prop->getDefaultValue() && null === $none->getDefaultValue() ? "default_ok\n" : "default_bad\n";
--EXPECT--
default_ok
