<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for PDO::__construct/prepare/query Reflection stubs (#24590). */
final class PdoConstructPrepareQueryReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'pdo_construct_prepare_query_reflection.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/pdo/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
