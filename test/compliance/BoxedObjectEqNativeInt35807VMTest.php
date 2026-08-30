<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: assigned object vs native int == (#35807 leftover of #35799).
 */
final class BoxedObjectEqNativeInt35807VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'boxed_object_eq_native_int.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/boxed_object_eq_native_int.phpt',
            'boxed_object_eq_native_int.phpt'
        );
    }
}
