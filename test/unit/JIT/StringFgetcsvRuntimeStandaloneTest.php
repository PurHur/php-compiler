<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringStreamCsv;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6750: AOT standalone must define fgetcsv CSV helpers without phpc_parse_csv_line in C.
 *
 * @group aot-lint
 */
final class StringFgetcsvRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesFgetcsvForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringStreamCsv::ensureLinked($ctx);

        foreach ([
            '__phpc_csv_parse_line',
            '__compiler_fgetcsv',
            '__compiler_str_getcsv',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
