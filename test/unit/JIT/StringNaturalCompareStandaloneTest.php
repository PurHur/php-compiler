<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringNaturalCompareJit;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5517: AOT standalone must define strnatcmp/strnatcasecmp without phpc_strnatcmp*.c.
 *
 * @group aot-lint
 */
final class StringNaturalCompareStandaloneTest extends TestCase
{
    public function testImplementDefinesNaturalCompareForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringNaturalCompareJit::implementStrnatcmp($ctx);
        StringNaturalCompareJit::implementStrnatcasecmp($ctx);

        foreach (['strnatcmp', 'strnatcasecmp'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
