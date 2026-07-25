<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\spl\SplArrayStorage;

/**
 * Thin JIT/AOT helper for special (array) casts before zend_std property enumeration.
 *
 * Order: ArrayObject/ArrayIterator backing (#19631), then the DateTime family —
 * DateTime, DateTimeImmutable, DateTimeZone, DateInterval, DatePeriod — Zend wire
 * (#22424, #22425, #22435). Returns null so the caller can fall back to
 * get_object_vars.
 *
 * php-src: ext/spl/spl_array.c — spl_array_get_properties_for(ZEND_PROP_PURPOSE_ARRAY_CAST)
 * php-src: ext/date/php_date.c — date_object_get_properties
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
        $obj = $src->toObject();
        $dup = SplArrayStorage::arrayCastDuplicate($obj);
        if (null !== $dup) {
            $out->array($dup);

            return $out;
        }
        $date = CastSupport::tryDateObjectArrayCast($obj);
        if (null !== $date) {
            $out->array($date);

            return $out;
        }
        $out->null();

        return $out;
    }
}
