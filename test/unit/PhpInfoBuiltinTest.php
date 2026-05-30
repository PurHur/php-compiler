<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\php_sapi_name;
use PHPCompiler\ext\standard\php_uname;
use PHPCompiler\ext\standard\phpversion;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for phpversion/php_sapi_name/php_uname (#3174). */
final class PhpInfoBuiltinTest extends TestCase
{
    public function testPhpversionReturnsCompilerVersion(): void
    {
        $runtime = new Runtime();
        $fn = new phpversion();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame(CompilerVersion::VERSION, $frame->returnVar->resolveIndirect()->toString());
    }

    public function testPhpSapiNameReturnsCli(): void
    {
        $runtime = new Runtime();
        $fn = new php_sapi_name();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame('cli', $frame->returnVar->resolveIndirect()->toString());
    }

    public function testPhpUnameSysnameNonEmpty(): void
    {
        $runtime = new Runtime();
        $fn = new php_uname();
        $frame = $fn->getFrame($runtime->vmContext);
        $mode = new VMVariable();
        $mode->string('s');
        $frame->calledArgs = [$mode];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertNotSame('', $frame->returnVar->resolveIndirect()->toString());
    }
}
