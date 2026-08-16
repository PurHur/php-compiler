<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: strcspn/strspn/strpbrk Reflection + Zend named params (#23400).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class StrcspnStrspnStrpbrkNamed23400VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'strcspn_strspn_strpbrk_named_23400.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/strcspn_strspn_strpbrk_named_23400.phpt',
            'strcspn_strspn_strpbrk_named_23400.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
