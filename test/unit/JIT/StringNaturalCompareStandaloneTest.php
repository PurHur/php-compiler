<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringNaturalCompare;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5517 / #13535: AOT standalone must define strnatcmp/strnatcasecmp via PHP helper bridge.
 *
 * @group aot-lint
 */
final class StringNaturalCompareStandaloneTest extends TestCase
{
    public function testImplementDefinesNaturalCompareForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringNaturalCompare::ensureStandaloneBodies($ctx);

        foreach (['strnatcmp', 'strnatcasecmp'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
