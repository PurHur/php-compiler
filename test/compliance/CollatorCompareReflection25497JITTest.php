<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: collator_compare()/collator_create() Reflection + named args (#25497).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 * @group jit
 */
final class CollatorCompareReflection25497JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'collator_compare_reflection_25497.phpt' => self::parsePHPT(
            __DIR__.'/cases/intl/collator_compare_reflection_25497.phpt',
            'collator_compare_reflection_25497.phpt'
        );
    }

    public function setUp(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('intl extension not advertised — collator_* withheld (#17694)');
        }
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
