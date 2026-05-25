<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #1360 */
final class ReadonlyClassTest extends TestCase
{
    public function testReadonlyClassAllowsAssignDuringConstruct(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class Box {
    public int $v;
    public function __construct(int $n) {
        $this->v = $n;
    }
}
echo (new Box(7))->v;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'readonly_construct.php'));
        $this->assertSame('7', ob_get_clean());
    }

    public function testReadonlyClassRejectsAssignAfterConstruct(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class Box {
    public int $v;
    public function __construct() {
        $this->v = 1;
    }
}
$o = new Box();
$o->v = 2;
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot modify readonly property Box::$v');
        $runtime->run($runtime->parseAndCompile($code, 'readonly_after.php'));
    }

    public function testReadonlyClassWithoutConstructorMarksConstructedOnNew(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class Box {
    public int $v;
}
$o = new Box();
$o->v = 2;
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot modify readonly property Box::$v');
        $runtime->run($runtime->parseAndCompile($code, 'readonly_no_ctor.php'));
    }

    public function testReadonlyClassFlagFromPhpCfg(): void
    {
        $this->assertTrue(\PHPCompiler\VM\ClassReadonly::fromClassFlags(
            \PhpParser\Node\Stmt\Class_::MODIFIER_READONLY
        ));
        $parser = new \PHPCfg\Parser((new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7));
        $nodes = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7)
            ->parse('<?php readonly class R {}');
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Class_::class, $nodes[0]);
        $this->assertTrue(\PHPCompiler\VM\ClassReadonly::fromClassFlags($nodes[0]->flags));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testReadonlyClassJitLowersAssignAfterConstructCheck(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
readonly class Box {
    public int $v;
    public function __construct() {
        $this->v = 1;
    }
}
$o = new Box();
$o->v = 2;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'readonly_jit_lower.php');
        $runtime->jitCompileBlock($block);
        $runtime->jitEmitInPlace();
        $ir = $runtime->loadJitContext()->module->printToString();
        self::assertStringContainsString(
            '__compiler_jit_raise_logic_exception',
            $ir,
            'JIT should lower readonly property write checks (#1360)'
        );
        self::assertStringContainsString(
            'readonly_violation',
            $ir,
            'JIT should branch to readonly violation before post-construct stores (#1360)'
        );
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testReadonlyClassJitCompileProbe(): void
    {
        $this->skipUnlessLlvmReady();
        $stderr = $this->runJitCompileProbe(<<<'PHP'
<?php
readonly class Box {
    public int $v;
}
PHP
        );
        self::assertStringNotContainsString(
            'Unknown JIT opcode',
            $stderr,
            'readonly class should lower for JIT (#1360)'
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
        $sourcePath = tempnam(sys_get_temp_dir(), 'readonly_jit_compile_');
        $this->assertNotFalse($sourcePath);
        $phpPath = $sourcePath.'.php';
        rename($sourcePath, $phpPath);
        file_put_contents($phpPath, $code);

        $probePath = tempnam(sys_get_temp_dir(), 'readonly_jit_compile_probe_');
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
