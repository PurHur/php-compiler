<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\EmbedObOutput;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9956: MCJIT embed ob echo must define helpers via EmbedObEchoBridge.
 *
 * @group aot-lint
 */
final class EmbedObOutputStandaloneTest extends TestCase
{
    public function testImplementDefinesEmbedObEchoForEmbedLoadType(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        $loadType = new \ReflectionProperty(Context::class, 'loadType');
        $loadType->setAccessible(true);
        $loadType->setValue($ctx, Builtin::LOAD_TYPE_EMBED);
        EmbedObOutput::implement($ctx);

        foreach (
            [
                '__phpc_ob_echo_cstr',
                '__phpc_ob_echo_ll',
                '__phpc_ob_echo_double',
                '__phpc_ob_echo_substr',
                '__phpc_ob_get_level',
            ] as $name
        ) {
            $fn = $ctx->module->getNamedFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
