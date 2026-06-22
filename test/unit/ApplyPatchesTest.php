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

    public function testApplyPatchesVerifyOnlyExitsZeroWhenMarkersPresent(): void
    {
        $script = self::$root.'/script/apply-patches.sh';
        if (!is_dir(self::$root.'/vendor/ircmaxell/php-cfg')) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $output = [];
        $exitCode = 0;
        exec('bash '.escapeshellarg($script).' --verify-only 2>&1', $output, $exitCode);
        $joined = implode("\n", $output);

        self::assertSame(0, $exitCode, "apply-patches --verify-only failed:\n".$joined);
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
        self::assertStringContainsString(
            'instanceof Op\\Type\\Intersection',
            $body,
            'php-types-intersection-type must lower Op\\Type\\Intersection in resolveOpType (#4956)'
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

    public function testPhpTypesUnionTypeTypeOverlayReappliesAfterPartialVendorDrift(): void
    {
        $typePhp = self::$root.'/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php';
        if (!is_readable($typePhp)) {
            self::markTestSkipped('vendor/ircmaxell/php-types not installed');
        }

        $original = (string) file_get_contents($typePhp);
        self::assertStringContainsString(
            'instanceof CfgType\\Union_',
            $original,
            'fixture must include CfgType\\Union_ handler in Type.php'
        );
        self::assertStringContainsString(
            'instanceof CfgType\\Intersection',
            $original,
            'fixture must include CfgType\\Intersection handler in Type.php'
        );

        $unionBlock = <<<'PHP'
        if ($decl instanceof CfgType\Union_) {
            $subs = [];
            foreach ($decl->types as $sub) {
                $subs[] = self::fromTypeDecl($sub);
            }

            return new self(self::TYPE_UNION, $subs);
        }
PHP;
        self::assertStringContainsString($unionBlock, $original);

        $stripped = str_replace($unionBlock, '', $original);
        file_put_contents($typePhp, $stripped);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertSame(0, $exitCode, "apply-patches failed after stripping Type.php Union_:\n".$joined);

            $restored = (string) file_get_contents($typePhp);
            self::assertStringContainsString(
                'instanceof CfgType\\Union_',
                $restored,
                'overlay must re-insert Union_ when Intersection handler remains (#7327)'
            );
            self::assertStringContainsString(
                'instanceof CfgType\\Intersection',
                $restored,
                'Intersection handler must remain after Union_ repair (#7327)'
            );
        } finally {
            file_put_contents($typePhp, $original);
        }
    }

    public function testPhpCfgThrowExprParserOverlayApplied(): void
    {
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        if (!is_readable($parser)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $body = (string) file_get_contents($parser);
        self::assertMatchesRegularExpression(
            '/parseExpr_Throw|Op\\\\Expr\\\\Throw_/',
            $body,
            'php-cfg-throw-expr overlay must register throw expressions (#3802, #4232)'
        );
    }

    public function testPhpTypesThrowExprOverlayApplied(): void
    {
        $recon = self::$root.'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        if (!is_readable($recon)) {
            self::markTestSkipped('vendor/ircmaxell/php-types not installed');
        }

        $body = (string) file_get_contents($recon);
        self::assertStringContainsString(
            "case 'Expr_Throw':",
            $body,
            'php-types-throw-expr overlay must type-reconstruct throw expressions (#3802, #5151)'
        );
    }

    public function testPhpTypesThrowExprOverlayReappliesAfterPartialVendor(): void
    {
        $recon = self::$root.'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        if (!is_readable($recon)) {
            self::markTestSkipped('vendor/ircmaxell/php-types not installed');
        }

        $original = (string) file_get_contents($recon);
        $stripped = str_replace("            case 'Expr_Throw':\n", '', $original);
        self::assertNotSame($original, $stripped, 'fixture must include Expr_Throw case arm');
        file_put_contents($recon, $stripped);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertSame(0, $exitCode, "apply-patches failed after stripping Expr_Throw:\n".$joined);

            $restored = (string) file_get_contents($recon);
            self::assertStringContainsString(
                "case 'Expr_Throw':",
                $restored,
                'overlay must re-insert Expr_Throw on harness tar-copy vendor (#5151)'
            );
        } finally {
            file_put_contents($recon, $original);
        }
    }

    public function testPhpCfgThrowExprParserOverlayReappliesAfterPartialVendor(): void
    {
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        $op = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Throw_.php';
        if (!is_readable($parser)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $originalParser = (string) file_get_contents($parser);
        $hadOp = is_readable($op);
        $originalOp = $hadOp ? (string) file_get_contents($op) : null;

        $stripped = preg_replace(
            '/    protected function parseExpr_Throw\(Expr\\\\Throw_ \$expr\)\s*\{.*?\n    \}\n/s',
            '',
            $originalParser
        );
        self::assertIsString($stripped);
        self::assertNotSame($originalParser, $stripped, 'fixture must include parseExpr_Throw');
        file_put_contents($parser, $stripped);
        if ($hadOp) {
            unlink($op);
        }

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertSame(0, $exitCode, "apply-patches failed after stripping throw-expr:\n".$joined);

            $restored = (string) file_get_contents($parser);
            self::assertMatchesRegularExpression(
                '/parseExpr_Throw|return new Op\\\\Expr\\\\Throw_/',
                $restored,
                'overlay must re-insert parseExpr_Throw on harness tar-copy vendor (#4232)'
            );
            self::assertFileIsReadable($op);
        } finally {
            file_put_contents($parser, $originalParser);
            if ($hadOp && $originalOp !== null) {
                $dir = dirname($op);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                file_put_contents($op, $originalOp);
            }
        }
    }

    public function testPhpCfgPropertyReadonlyOverlayApplied(): void
    {
        $prop = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php';
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        if (!is_readable($prop) || !is_readable($parser)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $body = (string) file_get_contents($prop);
        self::assertTrue(
            str_contains($body, 'public $readonly')
            || str_contains($body, 'propertyFlags'),
            'php-cfg-property-readonly overlay must expose readonly metadata (#3149, #4230)'
        );
        $parserBody = (string) file_get_contents($parser);
        self::assertMatchesRegularExpression(
            '/propertyFlags\s*=\s*\$node->flags|\$(cfgProp|prop)->readonly\s*=/',
            $parserBody,
            'php-cfg-property-readonly overlay must populate flags in parseStmt_Property (#4230)'
        );
    }

    public function testPhpCfgPropertyReadonlyOverlayReappliesAfterPartialVendor(): void
    {
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        if (!is_readable($parser)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $original = (string) file_get_contents($parser);
        $stripped = preg_replace(
            '/^\s*\$prop->propertyFlags = \$node->flags;\n/m',
            '',
            $original
        );
        self::assertIsString($stripped);
        self::assertNotSame($original, $stripped, 'fixture must include propertyFlags assignment');
        file_put_contents($parser, $stripped);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertSame(0, $exitCode, "apply-patches failed after stripping propertyFlags:\n".$joined);

            $restored = (string) file_get_contents($parser);
            self::assertMatchesRegularExpression(
                '/propertyFlags\s*=\s*\$node->flags/',
                $restored,
                'overlay must re-insert propertyFlags on harness tar-copy vendor (#4230)'
            );
        } finally {
            file_put_contents($parser, $original);
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

    public function testApplyPatchesInvokesInOperatorOverlayBeforeListSpread(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/apply-patches.sh');
        self::assertMatchesRegularExpression(
            '/apply_php_cfg_in_operator_overlay \|\| true[\s\S]*php-cfg-list-spread\.patch/s',
            $script,
            'In_ overlay must run before php-cfg-list-spread.patch (#4850)'
        );
    }

    public function testApplyPatchesInvokesListSpreadOverlayBeforeOptionalPatchFailures(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/apply-patches.sh');
        self::assertMatchesRegularExpression(
            '/apply_php_cfg_list_spread_overlay[\s\S]*php-cfg-loop-resolver-continue-switch-warning/s',
            $script,
            'list spread overlay must run before optional patch failures (#6069)'
        );
    }

    public function testVerifyCriticalLanguagePatchesIncludesListSpreadRhs(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/apply-patches.sh');
        self::assertStringContainsString(
            'php-cfg-list-spread-Assign',
            $script,
            'verify_critical_language_patches must require listSpreadRhs on Assign (#6069)'
        );
        self::assertStringContainsString(
            'php-cfg-list-spread-Parser',
            $script,
            'verify_critical_language_patches must require list spread Parser lowering (#6069)'
        );
    }

    public function testApplyPatchesDoesNotSwallowIncdecOverlayFailures(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/apply-patches.sh');
        self::assertStringNotContainsString(
            'apply_php_types_incdec_type_overlay || true',
            $script,
            'incdec TypeReconstructor overlay must fail CI when PostInc arms are missing (#6326)'
        );
        self::assertStringNotContainsString(
            'apply_php_cfg_incdec_expr_overlay || true',
            $script,
            'incdec Parser overlay must fail CI when PostInc lowering is missing (#6326)'
        );
    }

    public function testIncdecTypeOverlayFailureExitsNonZero(): void
    {
        $recon = self::$root.'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        if (!is_readable($recon)) {
            self::markTestSkipped('vendor/ircmaxell/php-types not installed');
        }

        $original = (string) file_get_contents($recon);
        $broken = preg_replace(
            "/\\s*case 'Expr_PostInc':.*?return false;\\n/s",
            '',
            $original
        );
        self::assertIsString($broken);
        self::assertNotSame($original, $broken, 'fixture must include inc/dec switch arms');
        $broken = str_replace(
            "throw new \\LogicException('Unknown variable op found: '.\$op->getType());",
            "throw new \\LogicException('incdec overlay test sentinel');",
            $broken
        );
        self::assertNotSame($original, $broken, 'fixture must include resolveVariableOp throw anchor');
        file_put_contents($recon, $broken);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertNotSame(0, $exitCode, "apply-patches must fail when incdec overlay cannot insert arms:\n".$joined);
            self::assertMatchesRegularExpression(
                '/php-types-incdec-type.*(overlay failed|marker not found|arms missing)/i',
                $joined,
                'failure must name php-types-incdec-type overlay (#6326)'
            );
            self::assertStringNotContainsString(
                'Applied php-types-incdec-type.patch (overlay): '.self::$root.'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php',
                $joined,
                'must not claim Applied when overlay did not insert PostInc arms (#6326)'
            );
        } finally {
            file_put_contents($recon, $original);
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            self::assertSame(0, $exitCode, 'apply-patches must restore vendor after incdec overlay test');
        }
    }

    public function testVerifyCriticalLanguagePatchesIncludesFirstClassCallableOverlay(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/apply-patches.sh');
        self::assertStringContainsString(
            'php-types-first-class-callable',
            $script,
            'verify_critical_language_patches must require FCC overlay (#6932)'
        );
        self::assertStringContainsString(
            'php-types-first-class-callable-Type-array-typo',
            $script,
            'verify_critical_language_patches must reject Type::array() FCC typo (#6932)'
        );
        self::assertStringContainsString(
            'apply_php_types_fcc_overlay_final_repair',
            $script,
            'apply-patches must run final FCC typo repair after php-types overlays (#6932)'
        );
    }

    public function testMagicScriptConstOverlayRunsBeforeFirstClassCallable(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/apply-patches.sh');
        $magicPos = strpos($script, 'apply_patch "$PATCH_DIR/php-types-magic-script-const.patch"');
        $fccPos = strpos($script, 'apply_patch "$PATCH_DIR/php-types-first-class-callable.patch"');
        self::assertNotFalse($magicPos, 'apply-patches must invoke php-types-magic-script-const.patch');
        self::assertNotFalse($fccPos, 'apply-patches must invoke php-types-first-class-callable.patch');
        self::assertLessThan(
            $fccPos,
            $magicPos,
            'magic-script-const overlay must run before first-class-callable (#1492 bootstrap-selfhost-helloworld)'
        );
    }

    public function testIncdecTypeOverlayDoesNotReintroduceFccTypeArrayTypo(): void
    {
        $recon = self::$root.'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        if (!is_readable($recon)) {
            self::markTestSkipped('vendor/ircmaxell/php-types not installed');
        }

        $original = (string) file_get_contents($recon);
        $broken = preg_replace(
            '/return \[new Type\(Type::TYPE_ARRAY\)\];/',
            'return [Type::array()];',
            $original,
            1
        );
        self::assertIsString($broken);
        self::assertNotSame($original, $broken, 'fixture must include FCC TYPE_ARRAY return');
        file_put_contents($recon, $broken);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertSame(0, $exitCode, "apply-patches must succeed and repair FCC typo:\n".$joined);
            $restored = (string) file_get_contents($recon);
            self::assertStringNotContainsString(
                'return [Type::array()];',
                $restored,
                'final FCC repair must replace Type::array() typo (#6932)'
            );
            self::assertStringContainsString(
                'new Type(Type::TYPE_ARRAY)',
                $restored,
                'FCC path must use TYPE_ARRAY constructor (#6932)'
            );
        } finally {
            file_put_contents($recon, $original);
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            self::assertSame(0, $exitCode, 'apply-patches must restore vendor after FCC repair test');
        }
    }

    public function testPhpCfgInOperatorOverlayApplied(): void
    {
        $op = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/In_.php';
        if (!is_dir(self::$root.'/vendor/ircmaxell/php-cfg')) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        self::assertFileIsReadable($op, 'php-cfg in-operator overlay must ship Op\\Expr\\In_ (#4682, #4850)');
        $body = (string) file_get_contents($op);
        self::assertStringContainsString('class In_ extends Expr', $body);
    }

    public function testPhpCfgInOperatorOverlayReappliesAfterPartialVendor(): void
    {
        $op = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/In_.php';
        if (!is_readable($op)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $original = (string) file_get_contents($op);
        unlink($op);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertSame(0, $exitCode, "apply-patches failed after removing In_.php:\n".$joined);
            self::assertFileIsReadable($op, 'In_ overlay must restore Op\\Expr\\In_ (#4850)');
        } finally {
            if (!is_readable($op)) {
                $dir = dirname($op);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                file_put_contents($op, $original);
            }
        }
    }

    public function testPhpCfgParserExtractsPromotedAsymmetricSetVisibility(): void
    {
        $parserFile = dirname(__DIR__, 2).'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        self::assertFileExists($parserFile);
        self::assertStringContainsString(
            'extractAsymmetricSetVisibilityFromAttributes',
            (string) file_get_contents($parserFile),
            'Parser must recover phpc-asymmetric-set markers (#4690)'
        );
        self::assertStringContainsString(
            'promotionSetVisibility = $this->extractAsymmetricSetVisibilityFromAttributes',
            (string) file_get_contents($parserFile)
        );
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
        self::assertTrue(
            str_contains($paramBody, 'promotionSetVisibility') || str_contains($paramBody, 'promotionFlags'),
            'Param must declare promotionSetVisibility or promotionFlags (#1492 partial vendor)'
        );
        self::assertStringContainsString('promotionGetVisibility', $paramBody, '#5059 Param promotionGetVisibility');
    }

    public function testPhpCfgAsymmetricVisibilityOverlayAddsPromotionGetVisibilityForUntypedSet(): void
    {
        $param = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php';
        if (!is_readable($param)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $original = (string) file_get_contents($param);
        $simulated = preg_replace(
            '/\n    \/\*\* Constructor promotion: asymmetric get visibility \(#5059\)\. \*\/\n    public int \$promotionGetVisibility = 0;\n/',
            "\n",
            $original,
            1
        );
        self::assertNotSame($original, $simulated, 'fixture must drop promotionGetVisibility');
        $simulated = (string) preg_replace(
            '/public int \$promotionSetVisibility/',
            'public $promotionSetVisibility',
            $simulated,
            1
        );
        file_put_contents($param, $simulated);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertStringContainsString('Param getVisibility overlay #5059', $joined, $joined);
            self::assertStringContainsString(
                'promotionGetVisibility',
                (string) file_get_contents($param),
                'overlay must add promotionGetVisibility for untyped promotionSetVisibility (#7468)'
            );
        } finally {
            file_put_contents($param, $original);
        }
    }

    public function testPhpCfgAsymmetricVisibilityOverlayAddsPromotionGetVisibilityForPromotionFlags(): void
    {
        $param = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php';
        if (!is_readable($param)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $original = (string) file_get_contents($param);
        $simulated = preg_replace(
            '/\n    \/\*\* Constructor promotion: asymmetric get visibility \(#5059\)\. \*\/\n    public int \$promotionGetVisibility = 0;\n/',
            "\n",
            $original,
            1
        );
        self::assertNotSame($original, $simulated, 'fixture must drop promotionGetVisibility');
        file_put_contents($param, $simulated);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertStringContainsString('Param getVisibility overlay #5059', $joined, $joined);
            self::assertStringContainsString(
                'promotionGetVisibility',
                (string) file_get_contents($param),
                'overlay must add promotionGetVisibility after promotionFlags (#5004)'
            );
        } finally {
            file_put_contents($param, $original);
        }
    }

    public function testPhpCfgAsymmetricVisibilityOverlayAddsPromotionSetVisibilityWhenOnlyPromotionFlags(): void
    {
        $prop = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php';
        $param = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php';
        if (!is_readable($prop) || !is_readable($param)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $originalProp = (string) file_get_contents($prop);
        $originalParam = (string) file_get_contents($param);
        $setBlock = "\n    /** PHP 8.4 asymmetric set visibility (0 = same as read; issue #3165). */\n    public int \$setVisibility = 0;\n";
        $getBlock = "\n    /** PHP 8.4 asymmetric get visibility (0 = same as write; issue #5059). */\n    public int \$getVisibility = 0;\n";
        $promoSetBlock = "\n    /** Constructor promotion: asymmetric set visibility (#3165). */\n    public int \$promotionSetVisibility = 0;\n";
        $promoGetBlock = "\n    /** Constructor promotion: asymmetric get visibility (#5059). */\n    public int \$promotionGetVisibility = 0;\n";
        self::assertStringContainsString($setBlock, $originalProp, 'fixture must include Property setVisibility');
        self::assertStringContainsString($getBlock, $originalProp, 'fixture must include Property getVisibility');
        self::assertStringContainsString($promoSetBlock, $originalParam, 'fixture must include Param promotionSetVisibility');
        self::assertStringContainsString($promoGetBlock, $originalParam, 'fixture must include Param promotionGetVisibility');
        $simProp = str_replace([$setBlock, $getBlock], ['', ''], $originalProp);
        $simParam = str_replace([$promoSetBlock, $promoGetBlock], ['', ''], $originalParam);
        self::assertNotSame($originalProp, $simProp, 'fixture must drop Property asymmetric fields');
        self::assertNotSame($originalParam, $simParam, 'fixture must drop Param asymmetric fields');
        self::assertStringContainsString('promotionFlags', $simParam, 'fixture must keep promotionFlags (#9031)');
        file_put_contents($prop, $simProp);
        file_put_contents($param, $simParam);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertStringContainsString(
                'Applied php-cfg-asymmetric-visibility.patch (Param overlay)',
                $joined,
                "asymmetric Param overlay must run on partial vendor (#9031):\n".$joined
            );
            self::assertStringContainsString(
                'promotionSetVisibility',
                (string) file_get_contents($param),
                'overlay must add promotionSetVisibility when only promotionFlags remains (#9031)'
            );
            self::assertStringContainsString(
                'public int $setVisibility',
                (string) file_get_contents($prop),
                'overlay must add Property setVisibility (#9031)'
            );
        } finally {
            file_put_contents($prop, $originalProp);
            file_put_contents($param, $originalParam);
        }
    }

    public function testPhpCfgCtorPromotionOverlayAddsFlagsForPartialVendorParam(): void
    {
        $param = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php';
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        if (!is_readable($param) || !is_readable($parser)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $originalParam = (string) file_get_contents($param);
        $originalParser = (string) file_get_contents($parser);
        $simParam = preg_replace(
            '/\n    \/\*\* Constructor property promotion visibility[^\n]*\n    public \$promotionFlags = 0;\n/',
            "\n",
            $originalParam,
            1
        );
        $simParser = preg_replace(
            '/\n            \$p->promotionFlags = \$param->flags & Stmt\\\\Class_::VISIBILITY_MODIFIER_MASK;\n/',
            "\n",
            $originalParser,
            1
        );
        if ($simParser === $originalParser) {
            $simParser = preg_replace(
                '/\n            \$p->promotionFlags = \$param->flags & Stmt\\Class_::VISIBILITY_MODIFIER_MASK;\n/',
                "\n",
                $originalParser,
                1
            );
        }
        self::assertStringContainsString(
            'promotionSetVisibility',
            $simParam,
            'fixture must keep partial-vendor promotionSetVisibility (#1492)'
        );
        self::assertStringNotContainsString('promotionFlags', $simParam, 'fixture must drop promotionFlags');
        self::assertStringNotContainsString('$p->promotionFlags', $simParser, 'fixture must drop Parser promotionFlags');
        file_put_contents($param, $simParam);
        file_put_contents($parser, $simParser);

        try {
            $output = [];
            $exitCode = 0;
            exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $joined = implode("\n", $output);
            self::assertSame(0, $exitCode, "apply-patches failed on partial vendor Param:\n".$joined);
            self::assertStringContainsString(
                'php-cfg-ctor-promotion.patch (overlay)',
                $joined,
                $joined
            );
            self::assertStringContainsString(
                'promotionFlags',
                (string) file_get_contents($param),
                'overlay must restore Param promotionFlags (#1492 partial vendor)'
            );
            self::assertStringContainsString(
                '$p->promotionFlags = $param->flags',
                (string) file_get_contents($parser),
                'overlay must restore Parser promotionFlags assignment'
            );
        } finally {
            file_put_contents($param, $originalParam);
            file_put_contents($parser, $originalParser);
        }
    }

    public function testPhpCfgMatchOverlayIncludesUnhandledMatchLowering(): void
    {
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        if (!is_readable($parser)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $body = (string) file_get_contents($parser);
        self::assertStringContainsString(
            'function parseExpr_Match',
            $body,
            'php-cfg-match overlay must register match lowering (#143)'
        );
        self::assertStringContainsString(
            'phpc_match_unhandled_operand_is_object',
            $body,
            'php-cfg-match overlay must probe object/enum operands for UnhandledMatchError (#5448, #7199)'
        );
        self::assertStringContainsString(
            'lowerUnhandledMatchError',
            $body,
            'php-cfg-match overlay must lower UnhandledMatchError for enum/scalar subjects (#5448)'
        );
    }

    public function testVerifyCriticalLanguagePatchesIncludesEnumBodyOverlays(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/apply-patches.sh');
        self::assertStringContainsString(
            'php-types-yield-from',
            $script,
            'verify_critical_language_patches must require Expr_YieldFrom in TypeReconstructor (#5004)'
        );
        self::assertStringContainsString(
            'php-cfg-enum-trait-use',
            $script,
            'verify_critical_language_patches must require TraitUse in parseStmt_Enum (#6625)'
        );
        self::assertStringContainsString(
            'php-cfg-enum-class-const-Parser',
            $script,
            'verify_critical_language_patches must require ClassConst in parseStmt_Enum (#6625)'
        );
        self::assertStringContainsString(
            'php-cfg-enum-case-isEnumCase',
            $script,
            'verify_critical_language_patches must require isEnumCase on parseEnumCase (#6625)'
        );
        self::assertStringContainsString(
            'php-cfg-enum-trait-use.patch)',
            $script,
            'patch_already_applied must probe php-cfg-enum-trait-use.patch (#6625)'
        );
        self::assertStringContainsString(
            'apply_patch_file_direct',
            $script,
            'apply-patches must expose direct patch helper (#9097)'
        );
        self::assertStringNotContainsString(
            'apply_patch "$PATCH_DIR/php-cfg-enum-trait-use.patch"',
            $script,
            'enum trait-use overlay must not recurse through apply_patch (#9097)'
        );
        self::assertStringContainsString(
            'Applied php-cfg-enum-trait-use.patch (overlay)',
            $script,
            'enum trait-use must use python overlay (#9097)'
        );
    }

    public function testEnumTraitUseOverlayDoesNotRecurse(): void
    {
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        if (!is_readable($parser)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $parserBody = (string) file_get_contents($parser);
        if (!preg_match('/function parseStmt_Enum.*?foreach \\(\\$node->stmts as \\$stmt\\).*?function parseEnumCase/s', $parserBody)) {
            self::markTestSkipped('parseStmt_Enum loop not present');
        }

        $stripped = str_replace(
            "} elseif (\$stmt instanceof Stmt\\TraitUse) {\n"
            ."                \$this->parseStmt_TraitUse(\$stmt);\n"
            ."            }\n",
            '',
            $parserBody
        );
        if ($stripped === $parserBody) {
            self::markTestSkipped('TraitUse branch not present to strip');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'phpc-parser-');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $stripped);

        $cmd = 'bash -c '.escapeshellarg(
            'set -euo pipefail; ROOT='.escapeshellarg(self::$root).'; PATCH_DIR="$ROOT/patches"; '
            .'source <(sed -n "/^patch_already_applied()/,/^apply_patch_file_direct()/p" '.escapeshellarg(self::$root.'/script/apply-patches.sh').' | head -n -1); '
            .'source <(sed -n "/^apply_patch_file_direct()/,/^apply_patch()/p" '.escapeshellarg(self::$root.'/script/apply-patches.sh').' | head -n -1); '
            .'source <(sed -n "/^apply_php_cfg_enum_trait_use_parser_fix()/,/^apply_php_cfg_enum_class_const_overlay()/p" '.escapeshellarg(self::$root.'/script/apply-patches.sh').' | head -n -1); '
            .'apply_php_cfg_enum_trait_use_parser_fix '.escapeshellarg($tmp)
        );
        $output = [];
        $exitCode = 0;
        exec('timeout 3 '.$cmd.' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, "enum trait-use overlay must finish without recursion:\n".implode("\n", $output));
        $repaired = (string) file_get_contents($tmp);
        @unlink($tmp);
        self::assertStringContainsString('Stmt\\TraitUse', $repaired, 'overlay must insert TraitUse branch (#9097)');
    }

    public function testApplyPatchesLeavesEnumBodyOverlaysOnVendorParser(): void
    {
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        $const = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php';
        if (!is_readable($parser) || !is_readable($const)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $output = [];
        $exitCode = 0;
        exec('bash '.escapeshellarg(self::$root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
        $joined = implode("\n", $output);
        self::assertSame(0, $exitCode, "apply-patches must succeed before enum marker check:\n".$joined);

        $parserBody = (string) file_get_contents($parser);
        $enumLoop = preg_match(
            '/function parseStmt_Enum.*?Stmt\\\\TraitUse.*?Stmt\\\\ClassConst/s',
            $parserBody
        );
        self::assertSame(1, $enumLoop, 'parseStmt_Enum must lower TraitUse and ClassConst (#6622, #6623)');
        self::assertStringContainsString('isEnumCase = true', $parserBody, 'parseEnumCase must set isEnumCase (#5054)');

        $constBody = (string) file_get_contents($const);
        self::assertStringContainsString('public bool $isEnumCase = false', $constBody);
        self::assertStringContainsString('public bool $enumCaseHasExplicitValue = false', $constBody);
    }
}
