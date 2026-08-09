<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EngineConstantDeprecation;
use PHPCompiler\VM\ErrorReporter;
use PHPUnit\Framework\TestCase;

/**
 * PROFILE≥8.4 E_STRICT constant fetch E_DEPRECATED (#29229).
 *
 * php-src: Zend/zend_constants.stub.php / zend_constants.c.
 */
final class EStrictConstantDeprecatedTest extends TestCase
{
    private function withProfile(string $profile, callable $fn): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE='.$profile);
        try {
            $fn();
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testGateAndMetadataUnderProfile84(): void
    {
        $this->withProfile('8.4', static function (): void {
            self::assertTrue(CompilerVersion::supportsEStrictConstantDeprecation());
            $meta = EngineConstantDeprecation::eStrictDeprecatedMetadata();
            self::assertInstanceOf(DeprecatedMetadata::class, $meta);
            self::assertSame('Constant E_STRICT is deprecated', $meta->formatGlobalConstant('E_STRICT'));
        });
    }

    public function testSilentUnderProfile83(): void
    {
        $this->withProfile('8.3', static function (): void {
            self::assertFalse(CompilerVersion::supportsEStrictConstantDeprecation());
            self::assertNull(EngineConstantDeprecation::eStrictDeprecatedMetadata());
        });
    }

    public function testContextRegistersUnderProfile84(): void
    {
        $this->withProfile('8.4', function (): void {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            self::assertArrayHasKey('e_strict', $ctx->globalConstDeprecated);
            self::assertSame(ErrorReporter::E_STRICT, Context::errorReportingConstant('E_STRICT'));
        });
    }

    public function testContextSilentUnderProfile82(): void
    {
        $this->withProfile('8.2', function (): void {
            $runtime = new Runtime();
            self::assertArrayNotHasKey('e_strict', $runtime->vmContext->globalConstDeprecated);
        });
    }
}
