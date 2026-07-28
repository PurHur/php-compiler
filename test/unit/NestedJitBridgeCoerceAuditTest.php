<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * NestedJIT ABI bridges must coerce payload/args per helper callee (#24465 / #24475).
 *
 * Reusing one coerceArgForHelper SSA across helpers whose NestedJIT formals diverge
 * (e.g. decodeInto `__string__*` vs decodeBool `__value__`) fails LLVM module verify.
 */
final class NestedJitBridgeCoerceAuditTest extends TestCase
{
    public function testStringJsonDecodeCoercesPayloadPerScalarHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecode.php');
        $this->assertStringContainsString('#24465', $source);
        $this->assertGreaterThanOrEqual(5, \substr_count($source, 'coerceArgForHelper'));
        foreach (['BOOL_HELPER', 'INT_HELPER', 'FLOAT_HELPER', 'STRING_HELPER'] as $helper) {
            $this->assertMatchesRegularExpression(
                '/helperFunction\(\s*\$context,\s*self::'.$helper.'\s*\)/',
                $source
            );
            // Each scalar helper must have its own getParam(0) coerce nearby (not shared decodeInto).
            $this->assertMatchesRegularExpression(
                '/\$\w+Helper\s*=\s*self::helperFunction\(\s*\$context,\s*self::'.$helper.'\s*\);\s*'.
                '\$\w+Arg\s*=\s*JitNestedHelperCoerce::coerceArgForHelper\(/s',
                $source,
                $helper.' must coerce against its own NestedJIT formal (#24465)'
            );
        }
        $violations = self::sharedCoerceViolations($source);
        $this->assertSame([], $violations, "StringJsonDecode shared-coerce: ".\implode('; ', $violations));
    }

    public function testBuiltinBridgesDoNotReuseOneCoerceAcrossDistinctHelpers(): void
    {
        $dir = __DIR__.'/../../lib/JIT/Builtin';
        $all = [];
        foreach (\glob($dir.'/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            if (!\str_contains($source, 'coerceArgForHelper')) {
                continue;
            }
            if (!\str_contains($source, 'helperFunction')) {
                continue;
            }
            $violations = self::sharedCoerceViolations($source);
            if ([] !== $violations) {
                $all[\basename($path)] = $violations;
            }
        }
        $this->assertSame(
            [],
            $all,
            "Shared coerceArgForHelper SSA across distinct helperFunction callees (#24475):\n".
            \json_encode($all, \JSON_PRETTY_PRINT)
        );
    }

    /**
     * Detect: $x = coerceArgForHelper(...); later used as arg to ≥2 distinct helperFunction callees.
     *
     * @return list<string>
     */
    private static function sharedCoerceViolations(string $source): array
    {
        $violations = [];
        if (!\preg_match_all(
            '/\$(\w+)\s*=\s*JitNestedHelperCoerce::coerceArgForHelper\s*\(/',
            $source,
            $assigns,
            \PREG_OFFSET_CAPTURE
        )) {
            return [];
        }
        foreach ($assigns[1] as [$var, $pos]) {
            $after = \substr($source, $pos);
            // Collect helper constants used in call(... helperFunction(..., self::FOO), $var)
            // and call($helperVar, $var) where $helperVar was bound to helperFunction(..., self::FOO).
            $helpers = [];
            if (\preg_match_all(
                '/->call\(\s*self::helperFunction\(\s*\$context,\s*self::(\w+)\s*\)\s*,\s*\$'.$var.'\b/',
                $after,
                $m1
            )) {
                foreach ($m1[1] as $h) {
                    $helpers[$h] = true;
                }
            }
            // Pattern from #24471: $boolHelper = helperFunction(..., BOOL_HELPER); call($boolHelper, $boolArg)
            // Track only when $var itself is the coerced arg passed to multiple *Helper locals that
            // were bound to distinct self::*_HELPER constants in the same function body.
            if (\preg_match_all(
                '/\$(\w+)\s*=\s*self::helperFunction\(\s*\$context,\s*self::(\w+)\s*\)\s*;[^;]{0,400}'.
                '->call\(\s*\$\1\s*,\s*\$'.$var.'\b/s',
                $after,
                $m2
            )) {
                foreach ($m2[2] as $h) {
                    $helpers[$h] = true;
                }
            }
            // Classic #24465 bug: one $payloadArg coerced once, then call(helperFunction(...), $payloadArg) ×N
            if (\preg_match_all(
                '/->call\(\s*self::helperFunction\(\s*\$context,\s*self::(\w+)\s*\)\s*,[\s\S]{0,80}?\$'.$var.'\b/',
                $after,
                $m3
            )) {
                foreach ($m3[1] as $h) {
                    $helpers[$h] = true;
                }
            }
            if (\count($helpers) >= 2) {
                $violations[] = '\$'.$var.' reused for helpers: '.\implode(', ', \array_keys($helpers));
            }
        }

        return $violations;
    }

    /** Synthetic: pre-#24471 shape must be flagged by the detector. */
    public function testDetectorFlagsSharedPayloadArgShape(): void
    {
        $bad = <<<'PHP'
        $payloadArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payloadOwned,
            self::helperFunction($context, self::DECODE_HELPER)->getParam(1)->typeOf()
        );
        $boolRaw = $context->builder->call(
            self::helperFunction($context, self::BOOL_HELPER),
            $payloadArg
        );
        $long = $context->builder->call(
            self::helperFunction($context, self::INT_HELPER),
            $payloadArg
        );
PHP;
        $violations = self::sharedCoerceViolations($bad);
        $this->assertNotSame([], $violations, 'detector must flag shared $payloadArg across BOOL/INT helpers');
    }
}
