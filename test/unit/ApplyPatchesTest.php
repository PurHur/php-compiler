<?php

declare(strict_types=1);

namespace Test\Unit;

use PHPUnit\Framework\TestCase;

final class ApplyPatchesTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testApplyPatchesExitsZeroAndNeverUsesAmbiguousSkipMessage(): void
    {
        $script = self::$root.'/script/apply-patches.sh';
        self::assertFileIsReadable($script);

        $output = [];
        $exitCode = 0;
        exec('bash '.escapeshellarg($script).' 2>&1', $output, $exitCode);
        $joined = implode("\n", $output);

        self::assertSame(0, $exitCode, "apply-patches failed:\n".$joined);
        self::assertStringNotContainsString(
            'already applied or failed',
            $joined,
            'patch failures must not be reported as ambiguous skips (#2724)'
        );
    }

    public function testPhpTypesUnionTypeReconstructorOverlayApplied(): void
    {
        $recon = self::$root.'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        if (!is_readable($recon)) {
            self::markTestSkipped('vendor/ircmaxell/php-types not installed');
        }

        $body = file_get_contents($recon);
        self::assertIsString($body);
        self::assertStringContainsString(
            'instanceof Op\\Type\\Union_',
            $body,
            'php-types-union-type must lower Op\\Type\\Union_ in resolveOpType (M2 spine compile)'
        );
    }
}
