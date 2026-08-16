<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: pow / sqrt / fmod Reflection + Zend named params (#23306).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class PowSqrtFmodNamed23306VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pow_sqrt_fmod_named_23306.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/pow_sqrt_fmod_named_23306.phpt',
            'pow_sqrt_fmod_named_23306.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
