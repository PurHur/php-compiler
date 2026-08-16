<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SimpleXMLElement::__construct(null) soft-null DEP then Exception (#31514). */
final class SimplexmlConstructNullSoft31514VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'construct_null_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/simplexml/construct_null_soft.phpt',
            'construct_null_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
