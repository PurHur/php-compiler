<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * JIT compile smoke for property-hook lowering (#3723).
 *
 * MCJIT execution of hook-bearing classes still crashes in harness (see JITTest skip);
 * this guards that LLVM lowering completes without CFG errors.
 */
final class PropertyHookJitCompileTest extends TestCase
{
    public function testPropertyHookScriptCompilesForJit(): void
    {
        $src = <<<'PHP'
<?php
class User {
    public string $email {
        set (string $value) {
            if (!str_contains($value, '@')) {
                echo "reject\n";
                return;
            }
            $this->email = $value;
        }
    }
}
$u = new User();
$u->email = 'bad';
$u->email = 'a@b.c';
echo $u->email, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompileEmitSmoke($src, 'property_hook_jit_compile.php');
        self::assertNotNull($block);
    }
}
