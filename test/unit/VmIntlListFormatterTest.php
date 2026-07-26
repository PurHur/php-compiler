<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\VmIntlListFormatter;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers issue #23229 */
final class VmIntlListFormatterTest extends TestCase
{
    private ?string $prevProfile = null;

    protected function setUp(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        $this->prevProfile = false === $prev ? null : $prev;
    }

    protected function tearDown(): void
    {
        if (null === $this->prevProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->prevProfile);
        }
    }

    public function testAdvertisedOnProfile85WithHostIntl(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('host php-intl required');
        }
        putenv('PHP_COMPILER_PROFILE=8.5');
        $this->assertTrue(CompilerVersion::advertisesIntlListFormatter());
        $this->assertTrue(IntlExtensionPolicy::advertisesIntlListFormatter());

        $runtime = new Runtime();
        $this->assertArrayHasKey(VmIntlListFormatter::CLASS_LC, $runtime->vmContext->classes);

        $object = new ObjectEntry($runtime->vmContext->classes[VmIntlListFormatter::CLASS_LC]);
        VmIntlListFormatter::initObject($object, 'en_US', VmIntlListFormatter::TYPE_AND, VmIntlListFormatter::WIDTH_WIDE);
        $this->assertSame('A, B, and C', VmIntlListFormatter::format($object, ['A', 'B', 'C']));
    }

    public function testWithheldOnProfile82(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('host php-intl required');
        }
        putenv('PHP_COMPILER_PROFILE=8.2');
        $this->assertFalse(CompilerVersion::advertisesIntlListFormatter());
        $this->assertFalse(IntlExtensionPolicy::advertisesIntlListFormatter());

        $runtime = new Runtime();
        $this->assertArrayNotHasKey(VmIntlListFormatter::CLASS_LC, $runtime->vmContext->classes);
    }

    public function testSerializeDenied(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $runtime = new Runtime();
        VmIntlListFormatter::registerClass($runtime->vmContext);
        $object = new ObjectEntry($runtime->vmContext->classes[VmIntlListFormatter::CLASS_LC]);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Serialization of 'IntlListFormatter' is not allowed");
        VmSerialize::serializeValue($runtime->vmContext, $var);
    }
}
