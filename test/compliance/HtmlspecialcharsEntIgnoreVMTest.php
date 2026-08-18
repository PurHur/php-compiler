<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: htmlspecialchars/htmlentities ENT_IGNORE skips invalid UTF-8 (#32063). */
final class HtmlspecialcharsEntIgnoreVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'htmlspecialchars_ent_ignore.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_ent_ignore.phpt',
            'htmlspecialchars_ent_ignore.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
