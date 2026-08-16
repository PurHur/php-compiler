<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
    10| * JIT: session_regenerate_id(null) soft Deprecated+Warning+false (#31444).
 */
final class SessionRegenerateIdNullSoftJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'session_regenerate_id_null_soft_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/session_regenerate_id_null_soft_jit.phpt',
            'session_regenerate_id_null_soft_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
