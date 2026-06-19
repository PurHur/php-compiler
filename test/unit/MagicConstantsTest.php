<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * OOP magic constants lowered at parse time (#199).
 */
final class MagicConstantsTest extends TestCase
{
    public function testClassAndFunctionInMethod(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function id(): string {
        return __CLASS__ . '::' . __FUNCTION__;
    }
}
echo (new C)->id();
PHP;
        $this->assertSame('C::id', $this->runVm($code));
    }

    public function testPropertyMagicConstInHook(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $p {
        get => __PROPERTY__;
    }
}
echo (new C)->p;
PHP;
        $this->assertSame('p', $this->runVm($code));
    }

    public function testPropertyMagicConstOutsideHookScope(): void
    {
        $code = <<<'PHP'
<?php
class P {
    public string $foo = '';
    public function m(): string {
        return __PROPERTY__;
    }
}
echo (new P)->m();
PHP;
        $this->assertSame('', $this->runVm($code));
    }

    public function testScriptDirAndFileMagicConstantsCompileAndRun(): void
    {
        $code = <<<'PHP'
<?php
$dir = __DIR__;
$file = __FILE__;
echo $dir, "\n", $file, "\n";
PHP;
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $script = '/tmp/example/magic_dir_file_probe.php';
        $block = $runtime->parseAndCompile($code, $script);
        $this->assertNotNull($block);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();

        $this->assertSame("/tmp/example\n/tmp/example/magic_dir_file_probe.php\n", is_string($out) ? $out : '');
    }

    public function testNamespaceAndClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Web;

echo __NAMESPACE__, "\n";

class Home {
    public function fqcn(): string {
        return __CLASS__;
    }
}

echo (new Home)->fqcn(), "\n";
PHP;
        $this->assertSame("App\Web\nApp\Web\Home\n", $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'magic-constants-test.php');
        $this->assertNotNull($block);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();

        return is_string($out) ? $out : '';
    }
}
