--TEST--
ReflectionProperty hasDefaultValue/getDefaultValue on promoted props (#22046)
--FILE--
<?php
declare(strict_types=1);

class PromotedHasDefaultC
{
    public function __construct(public int $x = 1, public string $y = 'a')
    {
    }
}

class PlainHasDefaultC
{
    public int $x = 1;
    public int $z;
}

$px = new ReflectionProperty(PromotedHasDefaultC::class, 'x');
$py = new ReflectionProperty(PromotedHasDefaultC::class, 'y');
$dx = new ReflectionProperty(PlainHasDefaultC::class, 'x');
$dz = new ReflectionProperty(PlainHasDefaultC::class, 'z');

echo !$px->hasDefaultValue() && null === $px->getDefaultValue() && $px->isPromoted() ? "px_ok\n" : "px_bad\n";
echo !$py->hasDefaultValue() && null === $py->getDefaultValue() && $py->isPromoted() ? "py_ok\n" : "py_bad\n";
echo $dx->hasDefaultValue() && 1 === $dx->getDefaultValue() && !$dx->isPromoted() ? "dx_ok\n" : "dx_bad\n";
echo !$dz->hasDefaultValue() && null === $dz->getDefaultValue() && !$dz->isPromoted() ? "dz_ok\n" : "dz_bad\n";
// Promoted names must be absent (JIT may include internal __phpc* pads).
$gdp = (new ReflectionClass(PromotedHasDefaultC::class))->getDefaultProperties();
echo !array_key_exists('x', $gdp) && !array_key_exists('y', $gdp) ? "gdp_ok\n" : "gdp_bad\n";
--EXPECT--
px_ok
py_ok
dx_ok
dz_ok
gdp_ok
