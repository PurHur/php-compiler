<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for propertyIsInitialized() JIT lowering (#6651).
 *
 * Uses bin/jit.php -l in a child process (issue #98).
 *
 * @group llvm
 */
final class PropertyIsInitializedJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::hasLibrary($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 not available — propertyIsInitialized JIT compile test needs MCJIT (#6651)');
        }
    }

    public function testPropertyIsInitializedModuleVerify(): void
    {
        $this->assertJitCompileOnly(<<<'PHP'
<?php
function probe($object) {
    return $object->propertyIsInitialized('slot');
}
class Holder {
    public $slot;
}
$b = new Holder();
var_export(probe($b));
$b->slot = 1;
var_export(probe($b));
PHP
        );
    }

    public function testPropertyIsInitializedUntypedReceiverModuleVerify(): void
    {
        $this->assertJitCompileOnly(<<<'PHP'
<?php
function probe($object) {
    return $object->propertyIsInitialized('x');
}
echo 1;
PHP
        );
    }

    private function assertJitCompileOnly(string $code): void
    {
        $dir = $this->repoRoot.'/var';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->fail('Could not create var/ for JIT compile probe');
        }
        $source = $dir.'/property-is-init-compile-'.getmypid().'-'.md5($code).'.php';
        file_put_contents($source, $code);

        $llvmDir = LlvmToolchain::resolveDir($this->repoRoot);
        $this->assertNotNull($llvmDir);

        $bash = <<<'BASH'
set -euo pipefail
ROOT=%s
SOURCE=%s
source "$ROOT/script/php-env.sh"
export PHP_COMPILER_LLVM_PATH=%s
export LD_LIBRARY_PATH="%s${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
unset PHP_COMPILER_SKIP_LLVM_PRELOAD
"$PHP_BIN" "${PHP_OPTS[@]}" "$ROOT/bin/jit.php" -l "$SOURCE"
BASH;

        $command = sprintf(
            $bash,
            escapeshellarg($this->repoRoot),
            escapeshellarg($source),
            escapeshellarg($llvmDir),
            $llvmDir
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', '-lc', $command], $descriptorSpec, $pipes, $this->repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        @unlink($source);

        $this->assertSame(0, $exit, trim((string) $stderr."\n".(string) $stdout));
    }
}
