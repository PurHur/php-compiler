<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\gethostname;
use PHPCompiler\ext\standard\VmHost;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for gethostname() (#3465, #5022). */
final class GethostnameBuiltinTest extends TestCase
{
    public function testTooManyArgsThrowsArgumentCountError(): void
    {
        $runtime = new Runtime();
        $fn = new gethostname();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[0]->int(1);
        $this->expectException(\ArgumentCountError::class);
        $this->expectExceptionMessage('gethostname() expects exactly 0 arguments, 1 given');
        $fn->execute($frame);
    }

    public function testReturnsHostName(): void
    {
        if (!VmHost::available()) {
            $this->markTestSkipped('libc FFI unavailable on this host');
        }

        $runtime = new Runtime();
        $fn = new gethostname();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $expected = VmHost::gethostname();
        if (false === $expected) {
            $this->assertTrue($resolved->toBool() === false);
        } else {
            $this->assertSame($expected, $resolved->toString());
        }
    }

    /** Zend basic_functions.stub.php: gethostname(): string|false (#28000). */
    public function testReflectionReturnIsStringOrFalse(): void
    {
        $code = <<<'PHP'
<?php
$r = new ReflectionFunction('gethostname');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
echo 'argc=', $r->getNumberOfParameters(), "\n";
$h = gethostname();
echo 'runtime=', (is_string($h) || $h === false) ? 'ok' : gettype($h), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'gethostname_reflect.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("return=string|false\nargc=0\nruntime=ok\n", ob_get_clean());
    }
}
