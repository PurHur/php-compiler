<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for collator_compare()/collator_create() Reflection stubs (#25497). */
final class CollatorCompareReflection25497VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'collator_compare_reflection_25497.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/intl/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('intl extension not advertised — collator_* withheld (#17694)');
        }
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
