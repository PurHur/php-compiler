<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

final class ReturnTypesTest extends TestCase
{
    public function testStrictModeRejectsStringForIntReturn(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
declare(strict_types=1);
function f(): int {
    return 'x';
}
f();
PHP;
        $this->expectException(\TypeError::class);
        $runtime->run($runtime->parseAndCompile($code, 'return_strict.php'));
    }

    public function testWeakModeCoercesStringForStringReturn(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function g(): string {
    return 'ok';
}
echo g();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'return_string.php'));
        $out = ob_get_clean();
        $this->assertSame('ok', $out);
    }

    public function testVoidMethodWithIncludeStatement(): void
    {
        $runtime = new Runtime();
        $dir = sys_get_temp_dir().'/phpc_return_type_'.getmypid();
        mkdir($dir);
        file_put_contents($dir.'/inc.php', "<?php\necho 'inc';\n");
        $code = <<<PHP
<?php
declare(strict_types=1);
class R {
    public function go(): void {
        include '{$dir}/inc.php';
    }
}
(new R())->go();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, $dir.'/main.php'));
        $out = ob_get_clean();
        $this->assertSame('inc', $out);
        @unlink($dir.'/inc.php');
        @rmdir($dir);
    }

    public function testVoidRejectsValueReturn(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function h(): void {
    return 1;
}
h();
PHP;
        $this->expectException(\TypeError::class);
        $runtime->run($runtime->parseAndCompile($code, 'return_void.php'));
    }
}
