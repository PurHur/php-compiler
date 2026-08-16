<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
    10| * VM: session_regenerate_id(null) soft Deprecated+Warning+false (#31444).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class SessionRegenerateIdNullSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'session_regenerate_id_null_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/session_regenerate_id_null_soft.phpt',
            'session_regenerate_id_null_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
