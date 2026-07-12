<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class MbEregTest extends TestCase
{
    public function testMbEregCaptureRegistersByRef(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$regs = [];
$ok = mb_ereg('([a-z]+)([0-9]+)', 'abc123', $regs);
echo $ok ? '1' : '0';
echo count($regs);
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'mb_ereg.php'));
        self::assertSame('13', ob_get_clean());
    }
}
