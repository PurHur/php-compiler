<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: htmlspecialchars/htmlentities ENT_IGNORE skips invalid UTF-8 (#32063). */
final class HtmlspecialcharsEntIgnoreJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'htmlspecialchars_ent_ignore_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_ent_ignore_jit.phpt',
            'htmlspecialchars_ent_ignore_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
