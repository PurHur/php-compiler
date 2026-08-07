<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for InflateContext / DeflateContext final (ext/zlib/zlib.stub.php; #28385). */
final class ZlibContextFinalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'zlib_context_class_final.phpt',
            'inflate_context_class_extend_final.phpt',
            'deflate_context_class_extend_final.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/zlib/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
