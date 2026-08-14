<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\TryCatchElseSupport;
use PHPCompiler\Compiler\CompileFatal;
use PHPUnit\Framework\TestCase;

/**
 * try/catch/else is a Zend Parse error on every php-src-strict profile (#31159).
 */
final class TryCatchElseSyntaxRejectorTest extends TestCase
{
    /** @return list<string> */
    public static function profileProvider(): array
    {
        return [
            'unset' => [''],
            '8.2' => ['8.2'],
            '8.4' => ['8.4'],
            '8.5' => ['8.5'],
        ];
    }

    /** @dataProvider profileProvider */
    public function testRejectsTryCatchElseOnPhpSrcStrictProfiles(string $profile): void
    {
        $this->withProfile($profile, function (): void {
            $this->assertFalse(CompilerVersion::supportsTryCatchElse());
            $this->expectException(CompileFatal::class);
            $this->expectExceptionMessage(TryCatchElseSupport::REFERENCE_PROFILE_UNEXPECTED_ELSE);

            TryCatchElseSyntaxRejector::reject(<<<'PHP'
<?php
try { echo "t"; } catch (Exception $e) { echo "c"; } else { echo "e"; }
PHP
                , 'test.php');
        });
    }

    public function testAllowsOrdinaryTryCatchFinally(): void
    {
        $code = <<<'PHP'
<?php
try {
    echo "t";
} catch (Exception $e) {
    echo "c";
} finally {
    echo "f";
}
PHP;
        self::assertSame($code, TryCatchElseSyntaxRejector::reject($code, 'test.php'));
    }

    public function testAllowsOrdinaryTryCatchWithoutElse(): void
    {
        $code = '<?php try { echo "t"; } catch (Exception $e) { echo "c"; }';
        self::assertSame($code, TryCatchElseSyntaxRejector::reject($code, 'test.php'));
    }

    /** @param callable(): void $fn */
    private function withProfile(string $profile, callable $fn): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        if ('' === $profile) {
            putenv('PHP_COMPILER_PROFILE');
            unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);
        } else {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $_ENV['PHP_COMPILER_PROFILE'] = $profile;
            $_SERVER['PHP_COMPILER_PROFILE'] = $profile;
        }
        try {
            $fn();
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
                $_SERVER['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
