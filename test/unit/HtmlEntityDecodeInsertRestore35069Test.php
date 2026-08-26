<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HtmlEntityDecodeJit must use a `__string__*` bridge (peer HtmlEntitiesJit #26889 / #35069).
 */
final class HtmlEntityDecodeInsertRestore35069Test extends TestCase
{
    public function testDecodeUsesBridgeNotHtmlspecialcharsDecodeDispatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HtmlEntityDecodeJit.php');
        $this->assertStringContainsString('__string__html_entity_decode', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringContainsString('#35069', $source);
        $this->assertStringNotContainsString("private const DISPATCH = '__compiler_html_entity_decode_dispatch'", $source);
        $this->assertMatchesRegularExpression(
            '/lookupFunction\(self::ABI\)/',
            $source
        );
    }
}
