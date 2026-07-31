<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * parent::__construct() / Class::__construct() when the target has no ctor (#25909).
 *
 * Zend/zend_object_handlers.c — "Cannot call constructor", not undefined-static-method.
 */
final class ParentConstructNoCtorTest extends TestCase
{
    public function testParentConstructNoCtorMessageOnVm(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/issue_25909_parent_construct_no_ctor.php';
        $this->assertFileExists($repro);

        $cmd = [PHP_BINARY, $root.'/bin/vm.php', $repro];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim((string) $stderr));
        $this->assertSame(
            "Error:Cannot call constructor\nnamed:Cannot call constructor\n",
            $stdout
        );
    }

    /**
     * @group llvm
     */
    public function testParentConstructNoCtorMessageOnJit(): void
    {
        require_once dirname(__DIR__).'/LlvmToolchain.php';
        $root = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($root);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM unavailable');
        }

        $repro = $root.'/test/repro/issue_25909_parent_construct_no_ctor.php';
        $cmd = [PHP_BINARY, $root.'/bin/jit.php', $repro];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim((string) $stderr));
        $this->assertSame(
            "Error:Cannot call constructor\nnamed:Cannot call constructor\n",
            $stdout
        );
    }

    public function testInheritedParentConstructStillRuns(): void
    {
        $runtime = new Runtime();
        ob_start();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class G { function __construct() { echo "G"; } }
class P extends G {}
class C extends P { function __construct() { parent::__construct(); } }
new C;
PHP
            ,
            'inherited_parent_ctor.php'
        );
        $this->assertNotNull($block);
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame('G', $out);
    }
}
