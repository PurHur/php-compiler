<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ParamArgumentCountError;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** `(new C)(...)` Zend ArgumentCountError + skip outer call without __invoke (#10176). */
final class ParensNewInvokeTest extends TestCase
{
    public function testVmParensNewMissingCtorArgIsArgumentCountError(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
try {
    (new class {
        public function __construct(public int $x) {}
    })(3);
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'parens_new_invoke.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('Too few arguments to function class@anonymous::__construct()', $out);
        $this->assertStringContainsString('0 passed in', $out);
        $this->assertStringContainsString('exactly 1 expected', $out);
    }

    public function testVmInvokableParensNewCallsInvoke(): void
    {
        $code = <<<'PHP'
<?php
echo (new class {
    public function __invoke(int $x): int { return $x * 2; }
})(4), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'parens_new_invoke_callable.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("8\n", ob_get_clean());
    }

    public function testFormatUserFunctionNameStripsAnonymousPathSuffix(): void
    {
        $name = "class@anonymous\0/path/file.php:5\$0::__construct";
        $this->assertSame('class@anonymous::__construct', ParamArgumentCountError::formatUserFunctionName($name));
    }
}
