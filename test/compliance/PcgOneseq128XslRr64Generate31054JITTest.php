<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: PcgOneseq128XslRr64::generate() bytes + nextInt sign (#31054).
 *
 * @group llvm
 * @group jit
 */
final class PcgOneseq128XslRr64Generate31054JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pcgoneseq128xslrr64_generate_stream.phpt' => self::parsePHPT(
            __DIR__.'/cases/random/pcgoneseq128xslrr64_generate_stream.phpt',
            'pcgoneseq128xslrr64_generate_stream.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
