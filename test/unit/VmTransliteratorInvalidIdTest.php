<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlError;
use PHPCompiler\ext\intl\VmTransliterator;
use PHPUnit\Framework\TestCase;

/**
 * Transliterator::create / transliterator_create unknown ID → U_INVALID_ID (#25355).
 *
 * php-src: ext/intl/transliterator/transliterator_methods.c (utrans_openU failure).
 * Exercises VmTransliterator via ICU FFI — does not require host php-intl advertisement.
 */
final class VmTransliteratorInvalidIdTest extends TestCase
{
    protected function setUp(): void
    {
        IntlError::clear();
    }

    public function test_create_unknown_id_sets_u_invalid_id(): void
    {
        $runtime = new Runtime();
        VmTransliterator::registerClass($runtime->vmContext);

        $object = VmTransliterator::create($runtime->vmContext, 'NoSuch-Rule');
        self::assertNull($object);
        self::assertSame(IntlError::U_INVALID_ID, IntlError::getCode());
        self::assertSame(65569, IntlError::getCode());
        self::assertSame('U_INVALID_ID', IntlError::errorName(IntlError::getCode()));
        self::assertSame(
            'transliterator_create: unable to open ICU transliterator with id "NoSuch-Rule": U_INVALID_ID',
            IntlError::getMessage()
        );
        self::assertTrue(IntlError::isFailure(IntlError::getCode()));
    }

    public function test_transliterate_id_unknown_sets_u_invalid_id(): void
    {
        $result = VmTransliterator::transliterateId('NoSuch-Rule', 'café');
        self::assertFalse($result);
        self::assertSame(IntlError::U_INVALID_ID, IntlError::getCode());
        self::assertSame(
            'transliterator_create: unable to open ICU transliterator with id "NoSuch-Rule": U_INVALID_ID',
            IntlError::getMessage()
        );
    }
}
