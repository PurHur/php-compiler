<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: SimpleXMLElement::xpath(null) soft-null DEP then warning + false (#31530).
 *
 * @group llvm
 * @group jit
 */
final class SimplexmlXpathNullSoft31530JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'xpath_null_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/simplexml/xpath_null_soft.phpt',
            'xpath_null_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
