<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Outline boxed assignment copy once per module (#36193).
 *
 * Call sites in JitValueBox must call __value__copy; the 15-block type switch lives in
 * VmValueCopy SSOT. php-src: zend_variables.h ZVAL_COPY.
 */
final class ValueBoxCopyOutlineTest extends TestCase
{
    public function testJitValueBoxCopyBetweenPointersCallsOutlinedHelper(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/JitValueBox.php');
        $this->assertStringContainsString('#36193', $src);
        $this->assertStringContainsString("lookupFunction('__value__copy')", $src);
        $this->assertStringContainsString('ValueBoxCopyJit::ensureLinked', $src);
        $this->assertStringNotContainsString('value_copy_string_', $src);
        $this->assertStringNotContainsString('copySeq', $src);
    }

    public function testContextLookupFunctionLazyLinksValueCopy(): void
    {
        $context = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertStringContainsString('#36193', $context);
        $pos = strpos($context, 'public function lookupFunction');
        $this->assertNotFalse($pos);
        $next = strpos($context, 'public function tryGetRegisteredFunction', $pos);
        $this->assertNotFalse($next);
        $body = substr($context, $pos, $next - $pos);
        $this->assertStringContainsString("'__value__copy' === \$name", $body);
        $this->assertStringContainsString('ValueBoxCopyJit::ensureLinked', $body);
    }

    public function testVmValueCopyHoldsTypeSwitchSsot(): void
    {
        $vm = (string) file_get_contents(dirname(__DIR__, 2).'/lib/VM/VmValueCopy.php');
        $this->assertStringContainsString('#36193', $vm);
        $this->assertStringContainsString('value_copy_string', $vm);
        // Inter-box copy uses separate — assignment addref stays in JitValueBox (#26367 / #36192).
        $this->assertStringContainsString("lookupFunction('__string__separate')", $vm);
        $this->assertStringNotContainsString('writeStringToValuePtrByAddref', $vm);
    }

    public function testValuePreDeclaresCopyShell(): void
    {
        foreach ([
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/Value.php',
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/Value.pre',
        ] as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString('__value__copy', $source, basename($path));
            $this->assertStringContainsString('#36193', $source, basename($path));
        }
    }
}
