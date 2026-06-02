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

    public function testPhpTypesUnionTypeReconstructorOverlayReappliesAfterPartialVendor(): void
    {
        $recon = self::$root.'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        if (!is_readable($recon)) {
            self::markTestSkipped('vendor/ircmaxell/php-types not installed');
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
            self::assertSame(0, $exitCode, "apply-patches failed after stripping Union_:\n".$joined);

            $restored = (string) file_get_contents($recon);
            self::assertStringContainsString(
                'instanceof Op\\Type\\Union_',
                $restored,
                'overlay must re-insert Union_ when Intersection handler is present (#4229)'
            );
        } finally {
            file_put_contents($recon, $original);
        }
    }

    public function testPhpCfgEnumParserPassesImplementsArrayNotBlock(): void
    {
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        if (!is_readable($parser)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $body = file_get_contents($parser);
        self::assertIsString($body);
        self::assertMatchesRegularExpression(
            '/function parseStmt_Enum[\s\S]*?parseExprList\(\$node->implements\)/',
            $body,
            'parseStmt_Enum must pass implements[] to Op\\Stmt\\Enum_ ctor (#3083, #3419)'
        );
        self::assertMatchesRegularExpression(
            '/new Op\\\\Stmt\\\\Enum_\([\s\S]*?\$flags,\s*\n\s*\$this->mapAttributes/',
            $body,
            'parseStmt_Enum must pass int $flags before attributes (#3114, Enum_ ctor)'
        );
    }

    public function testPhpCfgYieldFromOverlayApplied(): void
    {
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        $op = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/YieldFrom.php';
        if (!is_readable($parser)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        self::assertFileIsReadable($op, 'php-cfg yield-from overlay must ship Op\\Expr\\YieldFrom');
        $body = file_get_contents($parser);
        self::assertIsString($body);
        self::assertStringContainsString(
            'function parseExpr_YieldFrom',
            $body,
            'php-cfg yield-from overlay must register parseExpr_YieldFrom (#2997)'
        );
        self::assertFileDoesNotExist(
            self::$root.'/patches/php-cfg-yield-from.patch',
            'placeholder patch retired; overlay is invoked directly (#2997)'
        );
    }

    public function testApplyPatchesInvokesYieldFromOverlayDirectly(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/apply-patches.sh');
        self::assertStringContainsString('apply_php_cfg_yield_from_overlay', $script);
        self::assertStringNotContainsString('php-cfg-yield-from.patch', $script);
    }

    public function testApplyPatchesInvokesAsymmetricVisibilityOverlay(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/apply-patches.sh');
        self::assertStringContainsString('apply_php_cfg_asymmetric_visibility_overlay', $script);
    }

    public function testPhpCfgAsymmetricVisibilityFieldsPresentAfterApplyPatches(): void
    {
        $prop = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php';
        $param = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php';
        if (!is_readable($prop) || !is_readable($param)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $propBody = (string) file_get_contents($prop);
        $paramBody = (string) file_get_contents($param);
        self::assertStringContainsString('public int $setVisibility', $propBody, '#3165 Property setVisibility');
        self::assertSame(
            1,
            substr_count($paramBody, 'promotionSetVisibility'),
            'Param must declare promotionSetVisibility exactly once (#1492 partial vendor)'
        );
    }
}
