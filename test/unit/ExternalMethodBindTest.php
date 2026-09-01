<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPUnit\Framework\TestCase;

/**
 * Spine split-TU external-method bind gate (#24429).
 *
 * @group aot-lint
 */
final class ExternalMethodBindTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK);
        unset($_ENV[ExternalMethodBind::ENV_SPINE_CHUNK], $_SERVER[ExternalMethodBind::ENV_SPINE_CHUNK]);
        putenv(ExternalMethodBind::ENV_MANIFEST);
        unset($_ENV[ExternalMethodBind::ENV_MANIFEST], $_SERVER[ExternalMethodBind::ENV_MANIFEST]);
        putenv('PHP_COMPILER_HELPER_RUNTIME_O');
        unset($_ENV['PHP_COMPILER_HELPER_RUNTIME_O'], $_SERVER['PHP_COMPILER_HELPER_RUNTIME_O']);
        ExternalMethodBind::resetManifestForTests();
        parent::tearDown();
    }

    public function testSpineChunkModeOffByDefault(): void
    {
        $this->assertFalse(ExternalMethodBind::spineChunkMode());
    }

    public function testSpineChunkModeOptIn(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $this->assertTrue(ExternalMethodBind::spineChunkMode());
    }

    public function testAllowFallthroughWhenSpineChunk(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, JIT\Builtin::LOAD_TYPE_STANDALONE);
        $this->assertTrue(
            ExternalMethodBind::allowUnresolvedMethodFallthrough($ctx, 'object', null)
        );
    }

    /**
     * Dynamic `$class::method()` must be allowed to fall through under SPINE_CHUNK
     * the same way unresolved instance methods do (#24429 sockets/vm abort).
     */
    public function testSpineChunkAllowsObjectFallthroughForDynamicStaticClass(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $this->assertTrue(ExternalMethodBind::spineChunkMode());
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, JIT\Builtin::LOAD_TYPE_STANDALONE);
        $this->assertTrue(
            ExternalMethodBind::allowUnresolvedMethodFallthrough($ctx, 'object', null)
        );
        $proxy = $ctx->resolveFunctionProxy('object::paint');
        $this->assertInstanceOf(ExternalMethod::class, $proxy);
    }

    public function testAllowFallthroughForExternalOnlyClass(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, JIT\Builtin::LOAD_TYPE_STANDALONE);
        // Force-register an external class the way bundled lookups do.
        $ref = new \ReflectionClass($ctx->type->object);
        $method = $ref->getMethod('registerExternalClass');
        $method->setAccessible(true);
        $method->invoke($ctx->type->object, 'otherchunk\\widget', 'OtherChunk\\Widget');
        $id = $ctx->type->object->lookup('otherchunk\\widget');
        $this->assertTrue($ctx->type->object->isExternalOnlyClass($id));
        $this->assertTrue(
            ExternalMethodBind::allowUnresolvedMethodFallthrough($ctx, 'otherchunk\\widget', $id)
        );
        $this->assertFalse(
            ExternalMethodBind::allowUnresolvedMethodFallthrough($ctx, 'object', null)
        );
    }

    public function testResolveStillExternalMethodWithoutBind(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, JIT\Builtin::LOAD_TYPE_STANDALONE);
        $proxy = $ctx->resolveFunctionProxy('otherchunk\\widget::paint');
        $this->assertInstanceOf(ExternalMethod::class, $proxy);
    }

    public function testTryBindReturnsNullWhenSymbolUnknown(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, JIT\Builtin::LOAD_TYPE_STANDALONE);
        $this->assertNull(ExternalMethodBind::tryBind($ctx, 'otherchunk\\widget::paint'));
    }

    /**
     * Bound Native::$argTypes must be PHPLLVM\Type objects — string names make
     * getStringFromType() TypeError and break cold-build under helper-runtime (#24636).
     */
    public function testTryBindNativeArgTypesAreLlvmTypesNotStrings(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, JIT\Builtin::LOAD_TYPE_STANDALONE);
        $i64 = $ctx->getTypeFromString('int64');
        $void = $ctx->getTypeFromString('void');
        $fn = $ctx->module->addFunction(
            '__test_externalmethodbind_argtypes',
            $ctx->context->functionType($void, false, $i64)
        );
        $proxy = 'phpcompiler\\ext\\standard\\errorsilencejithelper::seterrorreporting';
        $ctx->functions[$proxy] = $fn;
        $bound = ExternalMethodBind::tryBind($ctx, $proxy);
        $this->assertInstanceOf(JIT\Call\Native::class, $bound);
        $this->assertCount(1, $bound->argTypes);
        $this->assertInstanceOf(\PHPLLVM\Type::class, $bound->argTypes[0]);
        $this->assertSame('int64', $ctx->getStringFromType($bound->argTypes[0]));
    }

    /**
     * Chunk TU compiles without registerModule() — stdlib Internal leaves must resolve from
     * Runtime modules under SPINE_CHUNK, not ExternalMethod-null (#36147).
     */
    public function testSpineChunkResolvesRuntimeInternalBuiltin(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, JIT\Builtin::LOAD_TYPE_STANDALONE);
        $proxy = $ctx->resolveFunctionProxy('count');
        $this->assertNotInstanceOf(ExternalMethod::class, $proxy);
        $this->assertInstanceOf(JIT\Call::class, $proxy);
        $strlen = $ctx->resolveFunctionProxy('strlen');
        $this->assertNotInstanceOf(ExternalMethod::class, $strlen);
    }

    /**
     * SPINE_CHUNK registers static proxies in source order — helpers used by findSlot must
     * precede it (#36155, same constraint as cfgVarRoot/resolveVariableName in #36166).
     */
    public function testBlockFindVariableHelpersPrecedeFindSlot(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/Block.php');
        $findSlot = strpos($source, 'public function findSlot(');
        $findVar = strpos($source, 'function findVariableInParentFrames(');
        $findByName = strpos($source, 'function findVariableInParentFramesByName(');
        $this->assertNotFalse($findSlot);
        $this->assertNotFalse($findVar);
        $this->assertNotFalse($findByName);
        $this->assertLessThan($findSlot, $findVar);
        $this->assertLessThan($findSlot, $findByName);
    }

    /**
     * Consumer chunk binds a producer symbol via manifest + bitcode (#36155 Phase C).
     */
    public function testTryBindFromChunkManifestBitcode(): void
    {
        $root = dirname(__DIR__, 2);
        $unitDir = $root.'/prelinked/helper-runtime/x86_64-linux/units/ext_standard_ErrorSilenceJitHelper_php';
        $bitcode = $unitDir.'/unit.bc';
        if (!is_file($bitcode)) {
            $this->markTestSkipped('helper-runtime ErrorSilenceJitHelper unit.bc not present');
        }
        $logical = 'phpcompiler\\ext\\standard\\errorsilencejithelper::seterrorreporting';
        $symbol = 'PHPCompiler_ext_standard_ErrorSilenceJitHelper__seterrorreporting';
        $manifestPath = sys_get_temp_dir().'/phpc_chunk_manifest_'.uniqid('', true).'.json';
        file_put_contents($manifestPath, json_encode([
            'bitcode' => $bitcode,
            'methods' => [
                $logical => ['symbol' => $symbol],
            ],
        ], JSON_UNESCAPED_SLASHES));
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        $_ENV['PHP_COMPILER_HELPER_RUNTIME_O'] = '0';
        putenv(ExternalMethodBind::ENV_MANIFEST.'='.$manifestPath);
        $_ENV[ExternalMethodBind::ENV_MANIFEST] = $manifestPath;
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, JIT\Builtin::LOAD_TYPE_STANDALONE);
        $bound = ExternalMethodBind::tryBind($ctx, $logical);
        @unlink($manifestPath);
        $this->assertInstanceOf(JIT\Call\Native::class, $bound);
        $this->assertCount(1, $bound->argTypes);
        $this->assertInstanceOf(\PHPLLVM\Type::class, $bound->argTypes[0]);
    }
}
