<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: ??= on uninitialized static property stores like Zend (#32035).
 */
final class StaticPropertyCoalesceAssign32035VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'static_prop_coalesce_assign_32035.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_prop_coalesce_assign_32035.phpt',
            'static_prop_coalesce_assign_32035.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
