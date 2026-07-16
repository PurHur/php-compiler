<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\spl\SplArrayStorage;

/**
 * Thin JIT/AOT helper for ArrayObject/ArrayIterator (array) cast (#19631).
 *
 * Returns a duplicated backing array when STD_PROP_LIST is unset; otherwise null
 * so the caller can fall back to zend_std property enumeration.
 *
 * php-src: ext/spl/spl_array.c — spl_array_get_properties_for(ZEND_PROP_PURPOSE_ARRAY_CAST)
 */
final class SplArrayCastJitHelper
{
    public static function tryArrayCastArgv(Variable $src): Variable
    {
        $out = new Variable();
        $src = $src->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $src->type) {
            $out->null();

            return $out;
        }
        $dup = SplArrayStorage::arrayCastDuplicate($src->toObject());
        if (null === $dup) {
            $out->null();

            return $out;
        }
        $out->array($dup);

        return $out;
    }
}
