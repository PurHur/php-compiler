<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for namespace bare undefined constant Error message (#10510). */
final class NsUndefinedBareConstantTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'ns_undefined_bare_constant.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/ns_undefined_bare_constant.phpt',
            'ns_undefined_bare_constant.phpt'
        );
    }

    public function testDefinedConstantInNamespaceStillResolves(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php namespace N; \define("N\\MY_CONST", 42); echo MY_CONST;',
            'ns_defined_constant.php'
        );
        ob_start();
        $runtime->run($block);
        $this->assertSame('42', ob_get_clean());
    }
}
