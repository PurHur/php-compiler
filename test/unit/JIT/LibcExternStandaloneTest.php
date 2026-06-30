<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * LibcExtern must declare strpbrk-family libc helpers before Module::jitInit (#1492 AOT gate).
 * strstr/strcasestr removed — string search uses JitStringSearch (#14070, #14080).
 *
 * @group aot-lint
 */
final class LibcExternStandaloneTest extends TestCase
{
    public function testStandaloneContextRegistersStrpbrkBeforeModuleJitInit(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);

        foreach (['strncasecmp', 'strpbrk'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
        }
    }

    public function testStrstrAbsentFromLibcExtern(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'strstr'", $source);
    }
}
