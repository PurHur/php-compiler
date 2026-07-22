<?php
/**
 * ReflectionProperty::hasDefaultValue()/getDefaultValue() on promoted props (#22046).
 * Zend: promoted → hasDefaultValue false / getDefaultValue null (default is on the param).
 */
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

foreach ([
    ['PromotedHasDefaultC', 'x', false, null, true],
    ['PromotedHasDefaultC', 'y', false, null, true],
    ['PlainHasDefaultC', 'x', true, 1, false],
    ['PlainHasDefaultC', 'z', false, null, false],
] as [$cls, $prop, $expectHas, $expectDef, $expectPromoted]) {
    $rp = new ReflectionProperty($cls, $prop);
    $has = $rp->hasDefaultValue();
    $def = $rp->getDefaultValue();
    $promoted = $rp->isPromoted();
    if ($has !== $expectHas || $def !== $expectDef || $promoted !== $expectPromoted) {
        echo "fail: {$cls}::\${$prop} has=", $has ? '1' : '0',
            ' def=', var_export($def, true),
            ' promoted=', $promoted ? '1' : '0', "\n";
        exit(1);
    }
}

echo "ok\n";
