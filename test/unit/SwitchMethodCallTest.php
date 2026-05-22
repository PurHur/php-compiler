<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Switch/if CFG branches must resolve $this for method calls (MiniWebApp Router, issue #210).
 */
final class SwitchMethodCallTest extends TestCase
{
    public function testSwitchCaseCallsPrivateMethod(): void
    {
        $code = <<<'PHP'
<?php
class R {
    public function dispatch(string $route): void {
        switch ($route) {
            case 'home':
                $this->renderHome();
                break;
        }
    }
    private function renderHome(): void {
        echo "home\n";
    }
}
(new R())->dispatch('home');
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'sw_meth_');
        $this->assertNotFalse($tmp);
        $path = $tmp.'.php';
        rename($tmp, $path);
        file_put_contents($path, $code);
        try {
            $vm = realpath(__DIR__.'/../../bin/vm.php');
            $this->assertNotFalse($vm);
            $cmd = array_merge(
                [PHP_BINARY],
                $this->extensionFlags(),
                [$vm, $path]
            );
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($vm));
            $this->assertIsResource($proc);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $this->assertSame(0, $code, $stderr !== false ? $stderr : '');
            $this->assertStringContainsString('home', $stdout !== false ? $stdout : '');
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    private function extensionFlags(): array
    {
        $flags = [];
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        if (!is_dir($extDir)) {
            return $flags;
        }
        foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
            $so = $extDir.'/'.$ext.'.so';
            if (is_file($so)) {
                $flags[] = '-d';
                $flags[] = 'extension='.$so;
            }
        }

        return $flags;
    }
}
