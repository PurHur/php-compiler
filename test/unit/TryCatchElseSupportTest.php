<?php

declare(strict_types=1);

namespace test\unit;

use PHPCompiler\Ast\TryCatchElseSupport;
use PHPCompiler\CompilerVersion;
use PHPUnit\Framework\TestCase;

final class TryCatchElseSupportTest extends TestCase
{
    protected function tearDown(): void
    {
        TryCatchElseSupport::beginCompilationUnit();
        parent::tearDown();
    }

    public function testExtractStripsElseAndQueuesBody(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            TryCatchElseSupport::beginCompilationUnit();
            $src = '<?php try { echo 1; } catch (Throwable $e) { } else { echo 2; }';
            $out = TryCatchElseSupport::extract($src);
            $this->assertStringNotContainsString('else', $out);
            $this->assertSame([' echo 2; '], TryCatchElseSupport::pendingElseSources());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testReferenceProfileSyntaxError(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $error = TryCatchElseSupport::referenceProfileSyntaxError(
                '<?php try { } catch (Throwable) { } else { }'
            );
            $this->assertNotNull($error);
            $this->assertSame(TryCatchElseSupport::REFERENCE_PROFILE_UNEXPECTED_ELSE, $error['message']);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testNoOpWhenProfileDisabled(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $src = '<?php try { } catch (Throwable) { } else { echo 1; }';
            $this->assertSame($src, TryCatchElseSupport::extract($src));
            $this->assertSame([], TryCatchElseSupport::pendingElseSources());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
