<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Config;

/**
 * External-method stub record/report + chunk method-manifest export for
 * {@see Context} (#36387).
 *
 * Extracted from {@see ContextFunctionProxyAndNestedJitKernel} so silent-null
 * stub accounting (#579) and Phase-C manifest export stay a separate TU from
 * function-proxy resolve / registerModule (split-TU / size-budget ratchet toward
 * ContextFunctionProxyAndNestedJitKernel ≤ 320 lines, #36199 / #36403).
 *
 * Used via {@code use ContextFunctionProxyExternalMethodStubReport;} on
 * {@see Context}. Invoked from {@see Call\ExternalMethod} and
 * {@see ContextCompileToFile}.
 *
 * No new C ABI. php-src analogy: missing class/method diagnostics and
 * zend_function symbol tables live beside the executor
 * (Zend/zend_execute_API.c, Zend/zend_compile.c function_table) rather than
 * inside proxy resolution.
 */
trait ContextFunctionProxyExternalMethodStubReport
{
    public function recordExternalMethodStub(string $proxyName): void
    {
        $this->externalMethodStubs[strtolower($proxyName)] = true;
    }

    /**
     * Surface methods that lowered to a silent null because their class is not in this module (#579).
     *
     * {@see Call\ExternalMethod} turns such a call into `__value__writeNull` with no diagnostic, so a
     * module that is missing a class miscompiles quietly rather than failing to build. The record was
     * write-only until now; this makes it readable, which is what any split-module work needs in order
     * to tell "compiled into another unit" apart from "silently became null".
     *
     * PHP_COMPILER_REPORT_EXTERNAL_STUBS=1 logs them; PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS=1 makes it
     * an error. Both are opt-in — some stubs are legitimate on bundles that intentionally exclude a
     * class, so this reports rather than assuming a defect.
     */
    public function reportExternalMethodStubs(): void
    {
        if ([] === $this->externalMethodStubs) {
            return;
        }
        $strict = '1' === Config::getenv('PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS');
        if (!$strict && '1' !== Config::getenv('PHP_COMPILER_REPORT_EXTERNAL_STUBS')) {
            return;
        }

        $names = array_keys($this->externalMethodStubs);
        sort($names, SORT_STRING);
        $jsonPath = Config::getenv('PHP_COMPILER_EXTERNAL_STUBS_JSON');
        if (is_string($jsonPath) && '' !== $jsonPath) {
            $payload = [
                'stub_count' => count($names),
                'stubs' => $names,
                'generated_at' => gmdate('c'),
            ];
            $dir = dirname($jsonPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('cannot create directory for external stubs JSON: '.$dir);
            }
            file_put_contents(
                $jsonPath,
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
        }
        $summary = sprintf(
            '%d method call(s) lowered to a silent null — class not in this module (#579): %s',
            count($names),
            implode(', ', array_slice($names, 0, 40)).(count($names) > 40 ? ', …' : '')
        );

        if ($strict) {
            throw new \RuntimeException('external method stubs: '.$summary);
        }
        if (\defined('STDERR') && \is_resource(STDERR)) {
            fwrite(STDERR, 'phpc: external method stubs — '.$summary."\n");
        }
    }

    /**
     * Producer chunk: write logical→symbol manifest for cross-TU bind (#36155 Phase C).
     *
     * PHP_COMPILER_EXTERNAL_METHOD_MANIFEST_EXPORT=/path/to/manifest.json
     * PHP_COMPILER_EMIT_BITCODE=/path/to/chunk.bc  (optional; recorded as relative path)
     */
    private function exportChunkMethodManifestIfRequested(): void
    {
        $exportPath = Config::getenv('PHP_COMPILER_EXTERNAL_METHOD_MANIFEST_EXPORT');
        if (!is_string($exportPath) || '' === $exportPath) {
            return;
        }
        $methods = [];
        foreach ($this->functionLlvmSymbols as $logical => $symbol) {
            if (!is_string($logical) || '' === $logical || !is_string($symbol) || '' === $symbol) {
                continue;
            }
            $methods[strtolower($logical)] = ['symbol' => $symbol];
        }
        ksort($methods, SORT_STRING);
        $bitcodeEnv = Config::getenv('PHP_COMPILER_EMIT_BITCODE');
        $bitcodeRel = null;
        if (is_string($bitcodeEnv) && '' !== $bitcodeEnv) {
            $manifestDir = dirname($exportPath);
            $bitcodeAbs = $bitcodeEnv;
            if (!str_starts_with($bitcodeAbs, '/')) {
                $bitcodeAbs = getcwd().'/'.$bitcodeAbs;
            }
            if (str_starts_with($bitcodeAbs, $manifestDir.'/')) {
                $bitcodeRel = substr($bitcodeAbs, \strlen($manifestDir) + 1);
            } else {
                $bitcodeRel = basename($bitcodeAbs);
            }
        }
        $payload = [
            'bitcode' => $bitcodeRel,
            'method_count' => count($methods),
            'methods' => $methods,
            'generated_at' => gmdate('c'),
        ];
        $dir = dirname($exportPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create directory for chunk method manifest: '.$dir);
        }
        file_put_contents(
            $exportPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
    }
}
