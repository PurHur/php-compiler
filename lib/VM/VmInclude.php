<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\OpCode;

/**
 * Include/require semantics SSOT for VM and compiled JIT/AOT (#10063, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_INCLUDE_OR_EVAL, once-guard, return value
 * php-src: main/fopen_wrappers.c — missing-file stream + Failed opening diagnostics (#30029)
 * php-src: Zend/zend_compile.c — include syntax failures become catchable ParseError (#32154)
 */
final class VmInclude
{
    /** Zend: skipped include_once on already-included file returns true; self-host stubs use int 1. */
    public const SKIPPED_SELFHOST_INCLUDE_RETURN = 1;

    /**
     * Opcode keyword for include/require diagnostics (zend_execute.c / fopen_wrappers.c).
     */
    public static function kindKeyword(int $kind): string
    {
        return match ($kind) {
            OpCode::INCLUDE_KIND_INCLUDE => 'include',
            OpCode::INCLUDE_KIND_INCLUDE_ONCE => 'include_once',
            OpCode::INCLUDE_KIND_REQUIRE => 'require',
            OpCode::INCLUDE_KIND_REQUIRE_ONCE => 'require_once',
            default => 'include',
        };
    }

    /**
     * First-step Warning when the include/require target cannot be opened (fopen_wrappers.c).
     */
    public static function failedToOpenStreamMessage(string $keyword, string $path): string
    {
        return \sprintf(
            '%s(%s): Failed to open stream: No such file or directory',
            $keyword,
            $path
        );
    }

    /**
     * Second-step Warning for include/include_once (zend_execute.c).
     */
    public static function failedOpeningForInclusionMessage(
        string $keyword,
        string $path,
        string $includePath
    ): string {
        return \sprintf(
            '%s(): Failed opening \'%s\' for inclusion (include_path=\'%s\')',
            $keyword,
            $path,
            $includePath
        );
    }

    /**
     * True when include/require compile failed with a Zend parser syntax error (#32154).
     *
     * php-src: zend_compile_file / ZEND_INCLUDE_OR_EVAL — syntax rejects throw catchable
     * ParseError into the caller; they must not abort the process as parseAndCompile failure.
     */
    public static function isCatchableSyntaxParseThrowable(\Throwable $e): bool
    {
        if ($e instanceof \ParseError || $e instanceof \PhpParser\Error) {
            return true;
        }

        return CompileFatal::isSyntaxParseErrorMessage(self::stripParserLineSuffix($e->getMessage()));
    }

    /**
     * php-parser / CompileFatal text toward Zend "syntax error, …" (zend_language_parser.y).
     */
    public static function syntaxParseMessage(\Throwable $e): string
    {
        return self::normalizeSyntaxParseMessage($e->getMessage());
    }

    public static function normalizeSyntaxParseMessage(string $detail): string
    {
        $message = trim($detail);
        if (str_starts_with(strtolower($message), 'parse error:')) {
            $message = trim(substr($message, strlen('Parse error:')));
        }
        $message = self::stripParserLineSuffix($message);
        if (str_starts_with($message, 'Syntax error,')) {
            return 'syntax error,'.substr($message, strlen('Syntax error,'));
        }

        return $message;
    }

    public static function syntaxParseLine(\Throwable $e): int
    {
        if ($e instanceof \PhpParser\Error) {
            $line = $e->getStartLine();
            if ($line > 0) {
                return $line;
            }
        }
        if ($e instanceof CompileFatal && $e->sourceLine > 0) {
            return $e->sourceLine;
        }
        if (preg_match('/\bon line (\d+)\b/', $e->getMessage(), $m)) {
            return max(1, (int) $m[1]);
        }
        if ($e->getCode() > 0) {
            return $e->getCode();
        }

        return 1;
    }

    /** php-parser appends " on line N"; Zend ParseError messages do not. */
    public static function stripParserLineSuffix(string $message): string
    {
        $message = trim($message);
        if (1 === preg_match('/^(.*) on line \d+$/', $message, $m)) {
            return trim($m[1]);
        }

        return $message;
    }

    /**
     * Fatal Error message for require/require_once after the stream Warning (zend_execute.c).
     */
    public static function failedOpeningRequiredMessage(string $path, string $includePath): string
    {
        return \sprintf(
            'Failed opening required \'%s\' (include_path=\'%s\')',
            $path,
            $includePath
        );
    }

    /**
     * Paths omitted from self-host spine bundles (argv driver, vendor autoload).
     *
     * @return list<string> normalized path suffixes
     */
    public static function selfHostSpineSkipPathSuffixes(): array
    {
        return [
            'src/cli.php',
            'src/cli_driver.php',
            'vendor/autoload.php',
        ];
    }

    public static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function pathMatchesSelfHostSpineSkipSuffix(string $path): bool
    {
        $normalized = self::normalizePath($path);
        foreach (self::selfHostSpineSkipPathSuffixes() as $suffix) {
            if ($normalized === $suffix || str_ends_with($normalized, '/'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function shouldSkipSelfHostSpineCliInclude(string $path): bool
    {
        $selfhost = getenv('PHP_COMPILER_SELFHOST_AOT');
        $cliSpine = getenv('PHP_COMPILER_CLI_SPINE_BUNDLE');
        $vmSpine = getenv('PHP_COMPILER_VM_SPINE_SMOKE');
        if (
            ('1' !== $selfhost && 'true' !== strtolower((string) $selfhost))
            && ('1' !== $cliSpine && 'true' !== strtolower((string) $cliSpine))
            && ('1' !== $vmSpine && 'true' !== strtolower((string) $vmSpine))
        ) {
            return false;
        }

        return self::pathMatchesSelfHostSpineSkipSuffix($path);
    }

    public static function callerIsSelfHostSpineSmokeEntry(string $callerPath): bool
    {
        $caller = self::normalizePath($callerPath);

        return str_ends_with($caller, '/test/selfhost/compiler_lib_spine_smoke/main.php');
    }

    public static function callerIsSelfHostSpineSmokeTree(string $callerPath): bool
    {
        $caller = self::normalizePath($callerPath);

        return str_contains($caller, '/test/selfhost/compiler_lib_spine_smoke/');
    }

    public static function callerIsCliEntry(string $callerPath): bool
    {
        $caller = self::normalizePath($callerPath);

        return str_ends_with($caller, '/bin/vm.php')
            || str_ends_with($caller, '/src/cli_driver.php');
    }

    /**
     * Stub dynamic requires while host-compiling M3 emit sidecars or full lib-spine AOT (#2699, #8559).
     */
    public static function shouldStubM3SidecarHostNonLiteralInclude(string $callerPath): bool
    {
        $isSpineSmokeEntry = self::callerIsSelfHostSpineSmokeEntry($callerPath);
        $isSpineSmokeTree = self::callerIsSelfHostSpineSmokeTree($callerPath);

        $sidecarHost = getenv('PHP_COMPILER_M3_SIDECAR_HOST');
        if ('1' === $sidecarHost || 'true' === strtolower((string) $sidecarHost)) {
            return self::callerIsCliEntry($callerPath) || $isSpineSmokeEntry;
        }

        $libSpineBundle = getenv('PHP_COMPILER_LIB_SPINE_BUNDLE');
        if ('1' === $libSpineBundle || 'true' === strtolower((string) $libSpineBundle)) {
            return true;
        }

        $selfhost = getenv('PHP_COMPILER_SELFHOST_AOT');
        if ('1' === $selfhost || 'true' === strtolower((string) $selfhost)) {
            return $isSpineSmokeEntry
                || $isSpineSmokeTree
                || self::callerIsCliEntry($callerPath)
                || str_ends_with(self::normalizePath($callerPath), '/src/cli.php');
        }

        return false;
    }
}
