<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StatPath;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9112: stat path runtime symbols must LLVM-lower in standalone AOT mode.
 *
 * @group aot-lint
 */
final class StatPathStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStatPathHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StatPath::ensureLinked($ctx);

        foreach ([
            '__phpc_jit_path_exists',
            '__phpc_jit_path_is_file',
            '__phpc_jit_stat_long_field',
            '__phpc_jit_filetype_label',
            '__phpc_jit_disk_free_bytes',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
