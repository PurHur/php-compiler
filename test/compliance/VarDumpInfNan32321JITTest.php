<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: var_dump(INF/NAN) zend_gcvt tokens (#32321).
 *
 * @group llvm
 */
final class VarDumpInfNan32321JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'var_dump_inf_nan.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/var_dump_inf_nan.phpt',
            'var_dump_inf_nan.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
