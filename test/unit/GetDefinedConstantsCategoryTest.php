<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmConstants;
use PHPCompiler\VM\Variable;

require_once __DIR__.'/../BaseTest.php';

/** get_defined_constants(category:) category filter (#12947, #17436). */
final class GetDefinedConstantsCategoryTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $root = __DIR__.'/../compliance/cases/stdlib';
        foreach ([
            'get_defined_constants_category_reference.phpt',
            'get_defined_constants_category_forward_84.phpt',
        ] as $file) {
            yield $file => self::parsePHPT($root.'/'.$file, $file);
        }
    }

    public function testCoreCategoryContainsPhpVersion(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $table = VmConstants::getDefinedConstantsForCategory($ctx, 'Core');
        $found = false;
        foreach ($table->iterateKeyed(true) as [$keyVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING === $key->type && 'PHP_VERSION' === $key->toString()) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Core category should include PHP_VERSION');
    }

    public function testUnknownCategoryIsEmpty(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $table = VmConstants::getDefinedConstantsForCategory($ctx, 'NoSuchExtensionCategory');
        self::assertSame(0, $table->getNumElements());
    }

    public function testAotFixtureExists(): void
    {
        $this->assertFileExists(
            __DIR__.'/../fixtures/aot/cases/get_defined_constants_category_forward_84.phpt'
        );
    }
}
