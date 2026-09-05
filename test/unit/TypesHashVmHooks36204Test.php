<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\ext\hash\JitHashCryptoKernel;
use PHPCompiler\ext\types\is_type;
use PHPCompiler\JIT\Builtin\IsNullFn;
use PHPCompiler\JIT\Builtin\StringHashCryptoPhp;
use PHPCompiler\JIT\SelfHostBuiltinPolicy;
use PHPCompiler\VM\HashVmRuntimeSupport;
use PHPCompiler\VM\TypesVmRuntimeSupport;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * lib must not import ext\types / ext\hash for IsNullFn, StringHashCryptoPhp, SelfHostBuiltinPolicy (#36204).
 */
final class TypesHashVmHooks36204Test extends TestCase
{
    protected function tearDown(): void
    {
        TypesVmRuntimeSupport::clear();
        HashVmRuntimeSupport::clear();
        parent::tearDown();
    }

    /** @return list<string> */
    private static function libPaths(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root.'/lib/JIT/Builtin/IsNullFn.php',
            $root.'/lib/JIT/Builtin/StringHashCryptoPhp.php',
            $root.'/lib/JIT/SelfHostBuiltinPolicy.php',
        ];
    }

    public function testLibSurfacesHaveNoDirectExtImports(): void
    {
        foreach (self::libPaths() as $path) {
            $src = (string) file_get_contents($path);
            self::assertStringNotContainsString(
                'PHPCompiler\\ext\\types',
                $src,
                basename($path).' must not import ext\\types'
            );
            self::assertStringNotContainsString(
                'PHPCompiler\\ext\\hash',
                $src,
                basename($path).' must not import ext\\hash'
            );
            self::assertStringNotContainsString(
                'PHPCompiler\\ext\\standard\\Module',
                $src,
                basename($path).' must not import ext\\standard\\Module'
            );
        }
    }

    public function testIsNullFnUsesTypesVmRuntimeSupport(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/IsNullFn.php');
        self::assertStringContainsString('TypesVmRuntimeSupport', $src);
        self::assertTrue(class_exists(IsNullFn::class));
    }

    public function testStringHashCryptoPhpUsesHashVmRuntimeSupport(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/StringHashCryptoPhp.php');
        self::assertStringContainsString('HashVmRuntimeSupport', $src);
        self::assertStringContainsString('ensureEvpLeaves', $src);
        self::assertTrue(class_exists(StringHashCryptoPhp::class));
    }

    public function testSelfHostBuiltinPolicyUsesExtensionRegistry(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/SelfHostBuiltinPolicy.php');
        self::assertStringContainsString('ExtensionRegistry', $src);
        self::assertTrue(class_exists(SelfHostBuiltinPolicy::class));
    }

    public function testTypesSupportUnsetAndRegister(): void
    {
        TypesVmRuntimeSupport::clear();
        self::assertNull(TypesVmRuntimeSupport::isNullCall());
        TypesVmRuntimeSupport::setIsNullCall(new is_type('is_null', Variable::TYPE_NULL));
        self::assertInstanceOf(is_type::class, TypesVmRuntimeSupport::isNullCall());
        TypesVmRuntimeSupport::clear();
        self::assertNull(TypesVmRuntimeSupport::isNullCall());
    }

    public function testTypesSupportUnsetThrowsOnCall(): void
    {
        TypesVmRuntimeSupport::clear();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('TypesVmRuntimeSupport is_null Call not registered');
        TypesVmRuntimeSupport::callIsNull(
            $this->createStub(\PHPCompiler\JIT\Context::class)
        );
    }

    public function testHashSupportUnsetIsNoopAndRegisterRuns(): void
    {
        HashVmRuntimeSupport::clear();
        HashVmRuntimeSupport::ensureEvpLeaves($this->createStub(\PHPCompiler\JIT\Context::class));
        $called = false;
        HashVmRuntimeSupport::setEnsureEvpLeaves(static function ($context) use (&$called): void {
            $called = true;
            unset($context);
        });
        HashVmRuntimeSupport::ensureEvpLeaves($this->createStub(\PHPCompiler\JIT\Context::class));
        self::assertTrue($called);
        self::assertTrue(method_exists(JitHashCryptoKernel::class, 'ensureEvpLeaves'));
    }

    public function testSupportClassesLoad(): void
    {
        self::assertTrue(class_exists(TypesVmRuntimeSupport::class));
        self::assertTrue(class_exists(HashVmRuntimeSupport::class));
    }
}
