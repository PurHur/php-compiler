<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: htmlspecialchars/htmlentities ENT_DISALLOWED → U+FFFD (#32084). */
final class HtmlspecialcharsEntDisallowedVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'htmlspecialchars_ent_disallowed.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_ent_disallowed.phpt',
            'htmlspecialchars_ent_disallowed.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
