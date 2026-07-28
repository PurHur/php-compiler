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
}
