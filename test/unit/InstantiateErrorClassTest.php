<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4281 */
final class InstantiateErrorClassTest extends TestCase
{
    public function testInterfaceInstantiationThrowsError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {}
new I();
PHP;
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot instantiate interface I');
        $runtime->run($runtime->parseAndCompile($code, 'interface_new.php'));
    }

}
