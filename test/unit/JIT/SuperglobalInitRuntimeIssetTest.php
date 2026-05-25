<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\SuperglobalInit;
use PHPUnit\Framework\TestCase;

/**
 * MCJIT embed and standalone AOT must not compile-time fold isset on refreshed sg_* (#1901).
 */
final class SuperglobalInitRuntimeIssetTest extends TestCase
{
    public function testRequiresRuntimeOffsetIsSetForEmbedAndStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        $loadType = new \ReflectionProperty(JIT\Context::class, 'loadType');
        $loadType->setAccessible(true);

        foreach (['_GET', '_POST', '_REQUEST'] as $name) {
            $loadType->setValue($ctx, Builtin::LOAD_TYPE_EMBED);
            $this->assertTrue(
                SuperglobalInit::requiresRuntimeOffsetIsSet($ctx, $name),
                $name.' embed'
            );
            $loadType->setValue($ctx, Builtin::LOAD_TYPE_STANDALONE);
            $this->assertTrue(
                SuperglobalInit::requiresRuntimeOffsetIsSet($ctx, $name),
                $name.' standalone'
            );
            $loadType->setValue($ctx, Builtin::LOAD_TYPE_IMPORT);
            $this->assertFalse(
                SuperglobalInit::requiresRuntimeOffsetIsSet($ctx, $name),
                $name.' import'
            );
        }

        $loadType->setValue($ctx, Builtin::LOAD_TYPE_EMBED);
        $this->assertFalse(
            SuperglobalInit::requiresRuntimeOffsetIsSet($ctx, '_SESSION')
        );
    }
}
