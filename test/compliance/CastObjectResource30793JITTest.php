<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: (object) cast / settype object on resource — stdClass.scalar (#30793).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class CastObjectResource30793JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'cast_object_resource.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/cast_object_resource.phpt',
            'cast_object_resource.phpt'
        );
        yield 'settype_object_resource.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/settype_object_resource.phpt',
            'settype_object_resource.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
