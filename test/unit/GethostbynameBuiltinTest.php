<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\gethostbyname;
use PHPCompiler\ext\standard\VmDns;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for gethostbyname() (#7419). */
final class GethostbynameBuiltinTest extends TestCase
{
    public function testVmDnsDoesNotDelegateToHostGethostbyname(): void
    {
        $src = (string) \file_get_contents(__DIR__.'/../../ext/standard/VmDns.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\gethostbyname\\s*\\(/', $src);
    }

    public function testLocalhostReturnsIpv4String(): void
    {
        if (false === VmDns::gethostbynamel('localhost')) {
            $this->markTestSkipped('native gethostbynamel(localhost) unavailable');
        }

        $runtime = new Runtime();
        $fn = new gethostbyname();
        $frame = $fn->getFrame($runtime->vmContext);
        $hostVar = new VMVariable();
        $hostVar->string('localhost');
        $frame->calledArgs = [$hostVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_STRING, $resolved->type);
        $this->assertMatchesRegularExpression('/^\d{1,3}(\.\d{1,3}){3}$/', $resolved->toString());
    }

    public function testUnknownHostReturnsHostname(): void
    {
        $runtime = new Runtime();
        $fn = new gethostbyname();
        $frame = $fn->getFrame($runtime->vmContext);
        $hostVar = new VMVariable();
        $hostVar->string('no-such-phpc-host.invalid.');
        $frame->calledArgs = [$hostVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $this->assertSame('no-such-phpc-host.invalid.', $resolved->toString());
    }

    /** @see https://github.com/PurHur/php-compiler/issues/20555 */
    public function testNullHostnameTypeErrorOnForward84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $runtime = new Runtime();
            $fn = new gethostbyname();
            $frame = $fn->getFrame($runtime->vmContext);
            $hostVar = new VMVariable();
            $hostVar->null();
            $frame->calledArgs = [$hostVar];
            $frame->returnVar = new VMVariable();
            $this->expectException(\TypeError::class);
            $this->expectExceptionMessage(
                'gethostbyname(): Argument #1 ($hostname) must be of type string, null given'
            );
            $fn->execute($frame);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
