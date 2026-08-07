<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT compliance: free-function isAnonymousClass() phantom (#28616).
 *
 * Dedicated provider so --filter is not tripped by path slashes in JITTest data-set names.
 */
require_once __DIR__.'/../BaseTest.php';

class IsAnonymousClassPhantomJITTest extends BaseTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'is_anonymous_class_phantom.phpt' => self::parsePHPT(
            __DIR__.'/cases/reflection/is_anonymous_class_phantom.phpt',
            'is_anonymous_class_phantom.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
