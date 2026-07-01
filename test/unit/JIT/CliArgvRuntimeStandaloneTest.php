<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\CliArgvRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issues #5407, #6341: AOT standalone must define CLI argv helpers without phpc_cli_argv.c.
 *
 * @group aot-lint
 */
final class CliArgvRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesCliArgvForStandalone(): void
    {
        $prev = getenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER');
        putenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1');
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            CliArgvRuntime::ensureLinked($ctx);

            foreach (
                [
                    '__phpc_cli_store_argv',
                    '__phpc_cli_argc',
                    '__phpc_cli_argv_cstr',
                    '__phpc_cli_str_eq',
                    '__phpc_cli_refresh_argv_global',
                ] as $name
            ) {
                $fn = $ctx->lookupFunction($name);
                $this->assertNotNull($fn);
                $this->assertGreaterThan(0, $fn->countBasicBlocks());
            }
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=');
            } else {
                putenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER='.$prev);
            }
        }
    }
}
