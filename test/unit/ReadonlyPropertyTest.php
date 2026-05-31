<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Block;
use PHPCompiler\LlvmToolchain;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3149 */
final class ReadonlyPropertyTest extends TestCase
{
    public function testReadonlyPropertyAllowsAssignDuringConstruct(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {
    public readonly int $v;
    public function __construct(int $n) {
        $this->v = $n;
    }
}
echo (new Box(7))->v;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'readonly_prop_construct.php'));
        $this->assertSame('7', ob_get_clean());
    }

    public function testReadonlyPropertyRejectsAssignAfterConstruct(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {
    public readonly int $v;
    public function __construct() {
        $this->v = 1;
    }
}
$o = new Box();
$o->v = 2;
PHP;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot modify readonly property Box::$v');
        $runtime->run($runtime->parseAndCompile($code, 'readonly_prop_after.php'));
    }

    public function testPromotedReadonlyPropertyRejectsAssignAfterConstruct(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public readonly string $id) {}
}
$c = new C('a');
$c->id = 'b';
PHP;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot modify readonly property C::$id');
        $runtime->run($runtime->parseAndCompile($code, 'readonly_promoted.php'));
    }

    public function testInheritedReadonlyPropertyUsesDeclaringClassInMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class P {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
class C extends P {}
$c = new C();
$c->x = 2;
PHP;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot modify readonly property P::$x');
        $runtime->run($runtime->parseAndCompile($code, 'readonly_inherited.php'));
    }

    public function testReadonlyPropertyRejectsUnset(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
unset($c->x);
PHP;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot unset readonly property C::$x');
        $runtime->run($runtime->parseAndCompile($code, 'readonly_prop_unset.php'));
    }

    public function testReadonlyPropertyRejectsPostIncrementAfterConstruct(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
$c->x++;
PHP;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot modify readonly property C::$x');
        $runtime->run($runtime->parseAndCompile($code, 'readonly_prop_post_inc.php'));
    }

    public function testReadonlyPropertyRejectsPreDecrementAfterConstruct(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
--$c->x;
PHP;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot modify readonly property C::$x');
        $runtime->run($runtime->parseAndCompile($code, 'readonly_prop_pre_dec.php'));
    }

    public function testReadonlyPropertyAllowsUnsetOnNonReadonlyProperty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public readonly int $x;
    public int $y = 1;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
unset($c->y);
echo isset($c->y) ? 'set' : 'unset';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'readonly_prop_unset_mutable.php'));
        $this->assertSame('unset', ob_get_clean());
    }

    public function testNonReadonlyPropertyStillMutable(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {
    public int $v = 1;
}
$o = new Box();
$o->v = 2;
echo $o->v;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'mutable_prop.php'));
        $this->assertSame('2', ob_get_clean());
    }

    public function testReadonlyPropertyDeclRequiresVmLoweringForBinJit(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class C {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
echo $c->x;
PHP,
            'readonly_prop_vm_lower.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsReadonlyPropertyOpcodes($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }

    public function testMutablePropertyDeclDoesNotRequireVmLoweringForBinJit(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class C {
    public int $x = 1;
}
$c = new C();
echo $c->x;
PHP,
            'mutable_prop_vm_lower.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsReadonlyPropertyOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }

    public function testReadonlyPropertyFlagFromPhpCfg(): void
    {
        $this->assertTrue(\PHPCompiler\VM\ClassReadonly::fromClassFlags(
            \PhpParser\Node\Stmt\Class_::MODIFIER_READONLY
        ));
        $nodes = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7)
            ->parse('<?php class C { public readonly int $x = 1; }');
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Class_::class, $nodes[0]);
        $prop = $nodes[0]->stmts[0];
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Property::class, $prop);
        $this->assertTrue(\PHPCompiler\VM\ClassReadonly::fromClassFlags($prop->flags));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testReadonlyPropertyJitLowersAssignAfterConstructCheck(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
class Box {
    public readonly int $v;
    public function __construct() {
        $this->v = 1;
    }
}
$o = new Box();
$o->v = 2;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'readonly_prop_jit_lower.php');
        $runtime->jitCompileBlock($block);
        $ir = $runtime->loadJitContext()->module->printToString();
        self::assertStringContainsString(
            '__compiler_jit_raise_logic_exception',
            $ir,
            'JIT should lower readonly property write checks (#3149)'
        );
        self::assertStringContainsString(
            'readonly_violation',
            $ir,
            'JIT should branch to readonly property violation before post-construct stores (#3149)'
        );
        self::assertStringContainsString(
            'readonly_allow_store',
            $ir,
            'JIT should allow readonly property stores while object is not constructed (#3149)'
        );
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testReadonlyPropertyJitLowersIncDecAfterConstructCheck(): void
    {
        $this->skipUnlessLlvmReady();
        $stderr = $this->runJitCompileProbe(<<<'PHP'
<?php
class C {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
$c->x++;
PHP
        );
        self::assertStringNotContainsString(
            'Unknown JIT opcode',
            $stderr,
            'readonly property ++ should lower for JIT (#3149)'
        );
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testReadonlyPropertyJitCompileProbe(): void
    {
        $this->skipUnlessLlvmReady();
        $stderr = $this->runJitCompileProbe(<<<'PHP'
<?php
class C {
    public readonly int $x;
}
PHP
        );
        self::assertStringNotContainsString(
            'Unknown JIT opcode',
            $stderr,
            'readonly property should lower for JIT (#3149)'
        );
    }

    private function skipUnlessLlvmReady(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                LlvmToolchain::readyFailureReason()
                ?? 'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
    }

    private function runJitCompileProbe(string $code): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $sourcePath = tempnam(sys_get_temp_dir(), 'readonly_prop_jit_compile_');
        $this->assertNotFalse($sourcePath);
        $phpPath = $sourcePath.'.php';
        rename($sourcePath, $phpPath);
        file_put_contents($phpPath, $code);

        $probePath = tempnam(sys_get_temp_dir(), 'readonly_prop_jit_compile_probe_');
        $this->assertNotFalse($probePath);
        $probePhp = $probePath.'.php';
        rename($probePath, $probePhp);
        file_put_contents($probePhp, <<<'PROBE'
<?php
require 'test/bootstrap.php';
PHPCompiler\LlvmToolchain::applyCurrentProcessEnv(dirname(__DIR__));
$source = $argv[1];
$code = file_get_contents($source);
$runtime = new PHPCompiler\Runtime();
$block = $runtime->parseAndCompile($code, basename($source));
try {
    $runtime->jit($block);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PROBE
        );

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $argv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $probePhp, $phpPath]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($argv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($phpPath);
        @unlink($probePhp);

        return $stderr;
    }
}
