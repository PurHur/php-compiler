<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringGettext;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * gettext LLVM helpers must lower for standalone AOT without StringGettextJit (#9859).
 *
 * @group aot-lint
 */
final class StringGettextRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGettextHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringGettext::ensureLinked($ctx);

        foreach ([
            '__compiler_gettext',
            '__compiler_dgettext',
            '__compiler_dcgettext',
            '__compiler_bindtextdomain',
            '__compiler_textdomain',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
