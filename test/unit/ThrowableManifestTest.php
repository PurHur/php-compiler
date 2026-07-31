<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ThrowableManifest;
use PHPCompiler\Runtime;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\StringableSupport;
use PHPUnit\Framework\TestCase;

/** Issue #6736: Throwable hierarchy manifest is SSOT for VM registration. */
final class ThrowableManifestTest extends TestCase
{
    public function testParentMapMatchesPhpSrcSubset(): void
    {
        $this->assertNull(ThrowableManifest::parentName('Exception'));
        $this->assertSame('Exception', ThrowableManifest::parentName('LogicException'));
        $this->assertSame('LogicException', ThrowableManifest::parentName('InvalidArgumentException'));
        $this->assertSame('BadFunctionCallException', ThrowableManifest::parentName('BadMethodCallException'));
        $this->assertSame('Exception', ThrowableManifest::parentName('ErrorException'));
        $this->assertNull(ThrowableManifest::parentName('Error'));
        $this->assertSame('Error', ThrowableManifest::parentName('TypeError'));
        $this->assertSame('TypeError', ThrowableManifest::parentName('ArgumentCountError'));
        $this->assertSame('ArithmeticError', ThrowableManifest::parentName('DivisionByZeroError'));
        // php-src Zend/zend_exceptions.stub.php (#25420).
        $this->assertSame('Error', ThrowableManifest::parentName('CompileError'));
        $this->assertSame('CompileError', ThrowableManifest::parentName('ParseError'));
    }

    public function testExceptionSupportConstantsTrackManifest(): void
    {
        $this->assertSame(ThrowableManifest::LC_THROWABLE, ExceptionSupport::CLASS_THROWABLE);
        $this->assertSame(ThrowableManifest::LC_EXCEPTION, ExceptionSupport::CLASS_EXCEPTION);
        $this->assertSame(ThrowableManifest::LC_ERROR_EXCEPTION, ExceptionSupport::CLASS_ERROR_EXCEPTION);
        $this->assertSame(ThrowableManifest::LC_TYPE_ERROR, ExceptionSupport::CLASS_TYPE_ERROR);
    }

    public function testVmRegistersEveryManifestClassWithParentChain(): void
    {
        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);

        $this->assertArrayHasKey(ThrowableManifest::LC_THROWABLE, $ctx->classes);
        $this->assertTrue($ctx->classes[ThrowableManifest::LC_THROWABLE]->isInterface);
        $throwable = $ctx->classes[ThrowableManifest::LC_THROWABLE];
        $this->assertContains(StringableSupport::INTERFACE_LC, $throwable->interfaces);
        foreach (
            [
                'getmessage',
                'getcode',
                'getfile',
                'getline',
                'gettrace',
                'getprevious',
                'gettraceasstring',
            ] as $methodLc
        ) {
            $this->assertArrayHasKey($methodLc, $throwable->abstractMethods, $methodLc);
            if ('getcode' !== $methodLc) {
                $this->assertArrayHasKey($methodLc, $throwable->methodReturnDeclaredTypes, $methodLc);
            }
        }
        $this->assertArrayNotHasKey('__tostring', $throwable->abstractMethods);
        $stringable = $ctx->classes[StringableSupport::INTERFACE_LC];
        $this->assertArrayHasKey('__tostring', $stringable->abstractMethods);
        $this->assertArrayHasKey('__tostring', $stringable->methodReturnDeclaredTypes);

        // php-src Zend/zend_exceptions.stub.php — Exception/Error keep Zend method casing + returns (#25868).
        $exception = $ctx->classes[ThrowableManifest::LC_EXCEPTION];
        $this->assertSame('getMessage', $exception->methodNames['getmessage'] ?? null);
        $this->assertArrayHasKey('getmessage', $exception->methodReturnDeclaredTypes);
        $error = $ctx->classes[ThrowableManifest::LC_ERROR];
        $this->assertSame('getMessage', $error->methodNames['getmessage'] ?? null);
        $this->assertArrayHasKey('getmessage', $error->methodReturnDeclaredTypes);

        foreach (ThrowableManifest::registrationOrder() as $className) {
            if (!ThrowableManifest::isAdvertised($className)) {
                continue;
            }
            $lc = ThrowableManifest::lcKey($className);
            $this->assertArrayHasKey($lc, $ctx->classes, $className);
            $entry = $ctx->classes[$lc];
            $this->assertSame($className, $entry->name);

            $parentLc = ThrowableManifest::parentLc($className);
            if (null === $parentLc) {
                $this->assertContains(
                    ThrowableManifest::LC_THROWABLE,
                    $entry->interfaces,
                    $className.' must implement Throwable'
                );
            } else {
                $this->assertSame($parentLc, $entry->parentLc, $className.' parent');
            }
        }
    }

    public function testErrorExceptionInstantiatesOnVm(): void
    {
        [$stdout, $exit] = $this->runVmCli(
            '<?php
try {
    throw new ErrorException("probe");
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
    echo $e instanceof Exception ? "instance_ok\n" : "instance_bad\n";
}
'
        );
        $this->assertSame(0, $exit);
        $this->assertSame("probe\ninstance_ok\n", $stdout);
    }

    public function testParseErrorIsCompileErrorOnVm(): void
    {
        [$stdout, $exit] = $this->runVmCli(
            '<?php
echo get_parent_class(ParseError::class), "\n";
$e = new ParseError("probe");
echo ($e instanceof CompileError) ? "instance_ok\n" : "instance_bad\n";
try {
    throw $e;
} catch (CompileError $c) {
    echo "caught=", get_class($c), "\n";
} catch (Throwable $t) {
    echo "miss=", get_class($t), "\n";
}
'
        );
        $this->assertSame(0, $exit);
        $this->assertSame("CompileError\ninstance_ok\ncaught=ParseError\n", $stdout);
    }

    /** @return array{0: string, 1: int} */
    private function runVmCli(string $code): array
    {
        $bin = realpath(__DIR__.'/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $php = getenv('PHP_COMPILER_PHP') ?: PHP_BINARY;
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$php, $bin], $descriptor, $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$stdout, $exit];
    }

    public function testDescendantChecksUseManifestTree(): void
    {
        $this->assertTrue(ThrowableManifest::isDescendantOf('invalidargumentexception', 'exception'));
        $this->assertTrue(ThrowableManifest::isDescendantOf('argumentcounterror', 'error'));
        $this->assertFalse(ThrowableManifest::isDescendantOf('exception', 'error'));
        $this->assertTrue(ExceptionSupport::isBuiltinExceptionSubclass('errorexception'));
        $this->assertFalse(ExceptionSupport::isBuiltinErrorSubclass('error'));
    }
}
