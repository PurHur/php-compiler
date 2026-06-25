<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * LibcExtern must declare strstr-family libc helpers before Module::jitInit (#1492 AOT gate).
 *
 * @group aot-lint
 */
final class LibcExternStandaloneTest extends TestCase
{
    public function testStandaloneContextRegistersStrstrBeforeModuleJitInit(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);

        foreach (['strstr', 'strcasestr', 'strncasecmp', 'strpbrk'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
        }
    }
}
