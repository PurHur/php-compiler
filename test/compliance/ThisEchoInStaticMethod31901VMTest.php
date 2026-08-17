<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: echo/print $this in a static method throws Error (#31901).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ThisEchoInStaticMethod31901VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'this_echo_in_static_method.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/this_echo_in_static_method.phpt',
            'this_echo_in_static_method.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
