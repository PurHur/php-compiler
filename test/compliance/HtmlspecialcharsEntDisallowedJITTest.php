<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: htmlspecialchars/htmlentities ENT_DISALLOWED → U+FFFD (#32084). */
final class HtmlspecialcharsEntDisallowedJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'htmlspecialchars_ent_disallowed_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_ent_disallowed_jit.phpt',
            'htmlspecialchars_ent_disallowed_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
