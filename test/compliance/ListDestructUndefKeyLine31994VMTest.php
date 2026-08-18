<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: keyed list destruct Undefined array key Warning cites list site (#31994).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ListDestructUndefKeyLine31994VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'list_destruct_undef_key_warning_line.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/list_destruct_undef_key_warning_line.phpt',
            'list_destruct_undef_key_warning_line.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
