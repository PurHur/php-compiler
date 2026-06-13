<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Ast\MultiBlockNameResolver;

/** Multi-block namespace use imports (#4425). */
final class MultiBlockNameResolverTest extends TestCase
{
    private const CODE = <<<'PHP'
<?php
namespace N1;

use N2\C as Aliased;
use function N2\f as ff;
use const N2\K as KK;

class C { }

namespace N2;
const K = 123;
function f() { return __NAMESPACE__; }
class C { }

namespace N1;

echo Aliased::class, "\n";
echo ff(), "\n";
echo KK, "\n";
echo C::class, "\n";
echo \N2\C::class, "\n";
PHP;

    private const EXPECT = <<<'TXT'
N2\C
N2
123
N1\C
N2\C
TXT;

    public function testVmCrossBlockNamespaceUseImports(): void
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile(self::CODE, 'namespaces_cross_block.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(self::EXPECT, rtrim((string) ob_get_clean()));
    }

    /** Consecutive file parses in the same namespace must not restore stale use imports (#4425). */
    public function testConsecutiveFileParsesSameNamespaceDoNotCollideOnUseImports(): void
    {
        $rt = new Runtime(Runtime::MODE_AOT);
        $root = dirname(__DIR__, 3);
        $rt->parseAndCompileFile($root.'/lib/JIT/JitStringCompare.php');
        $block = $rt->parseAndCompileFile($root.'/lib/JIT/M3EmitTuTrivialEchoAot.php');
        $this->assertNotNull($block);
    }
}
