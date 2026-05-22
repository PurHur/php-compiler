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
