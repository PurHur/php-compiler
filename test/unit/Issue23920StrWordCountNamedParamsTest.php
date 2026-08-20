<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * BuiltinParamNames for str_word_count — Zend stub characters (#23920).
 */
final class Issue23920StrWordCountNamedParamsTest extends TestCase
{
    public function testBuiltinParamNames(): void
    {
        self::assertSame(
            ['string', 'format=', 'characters='],
            BuiltinParamNames::forFunction('str_word_count')
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('str_word_count'),
            'string'
        ));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('str_word_count'),
            'characters'
        ));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('str_word_count'),
            'charlist'
        ));
    }
}
