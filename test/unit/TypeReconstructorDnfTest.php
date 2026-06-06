<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: php-types TypeReconstructor must resolve DNF Op\Type Union_/Intersection
 * on vendor/ and prelinked/bootstrap-vendor/sources/ (#6820, #6817).
 */
final class TypeReconstructorDnfTest extends TestCase
{
    private static string $root;

    /** @var list<string> */
    private const RECONSTRUCTOR_PATHS = [
        'vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php',
        'prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php',
    ];

    /** @var list<string> */
    private const TYPE_PATHS = [
        'vendor/ircmaxell/php-types/lib/PHPTypes/Type.php',
        'prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function reconstructorPathsProvider(): iterable
    {
        foreach (self::RECONSTRUCTOR_PATHS as $rel) {
            yield $rel => [$rel];
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function typePathsProvider(): iterable
    {
        foreach (self::TYPE_PATHS as $rel) {
            yield $rel => [$rel];
        }
    }

    /** @dataProvider reconstructorPathsProvider */
    public function testTypeReconstructorHasDnfOpTypeHandlers(string $rel): void
    {
        $path = self::$root.'/'.$rel;
        if (!is_readable($path)) {
            self::markTestSkipped($rel.' not present');
        }

        $body = (string) file_get_contents($path);
        self::assertStringContainsString(
            'instanceof Op\\Type\\Union_',
            $body,
            $rel.' must lower Op\\Type\\Union_ in resolveOpType (#6820)'
        );
        self::assertStringContainsString(
            'instanceof Op\\Type\\Intersection',
            $body,
            $rel.' must lower Op\\Type\\Intersection in resolveOpType (#6820)'
        );
        $lintOut = [];
        $lintCode = 0;
        exec('php -l '.escapeshellarg($path).' 2>&1', $lintOut, $lintCode);
        self::assertSame(0, $lintCode, $rel.' must parse: '.implode("\n", $lintOut));
    }

    /** @dataProvider typePathsProvider */
    public function testTypeFromDeclHasDnfCfgHandlers(string $rel): void
    {
        $path = self::$root.'/'.$rel;
        if (!is_readable($path)) {
            self::markTestSkipped($rel.' not present');
        }

        $body = (string) file_get_contents($path);
        self::assertStringContainsString(
            'instanceof CfgType\\Union_',
            $body,
            $rel.' must lower CfgType\\Union_ in fromTypeDecl (#6820)'
        );
        self::assertStringContainsString(
            'instanceof CfgType\\Intersection',
            $body,
            $rel.' must lower CfgType\\Intersection in fromTypeDecl (#6820)'
        );
    }

    public function testApplyPatchesReappliesPrelinkedUnionHandlerAfterStrip(): void
    {
        $recon = self::$root.'/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        if (!is_readable($recon)) {
            self::markTestSkipped('prelinked TypeReconstructor not present');
        }

        $original = (string) file_get_contents($recon);
        $block = <<<'PHP'
        } elseif ($type instanceof Op\Type\Union_) {
            $subs = [];
            foreach ($type->types as $sub) {
                $subs[] = $this->resolveOpType($sub);
            }

            return (new Type(Type::TYPE_UNION, $subs))->simplify();
PHP;
        self::assertStringContainsString($block, $original, 'fixture must include Union_ handler');

        $stripped = str_replace($block, '', $original);
        file_put_contents($recon, $stripped);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertSame(0, $exitCode, "apply-patches failed after stripping prelinked Union_:\n".$joined);

            $restored = (string) file_get_contents($recon);
            self::assertStringContainsString(
                'instanceof Op\\Type\\Union_',
                $restored,
                'overlay must re-insert Union_ on prelinked bootstrap vendor sources (#6820)'
            );
        } finally {
            file_put_contents($recon, $original);
        }
    }
}
