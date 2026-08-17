<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Frame;
use PHPCompiler\Runtime;
use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\Variable;

/**
 * eval() — compile and execute PHP source in the caller scope (Zend zend_eval_string parity, #3358).
 */
final class VmEval
{
    public const EVAL_FILENAME = "eval()'d code";

    /** php-src Zend/zend_compile.c — zend_add_member_modifier() (#25114, #6774). */
    public const MULTIPLE_ACCESS_MODIFIERS_MESSAGE = 'Multiple access type modifiers are not allowed';

    /**
     * Zend __FILE__ / exception file shape for eval'd code (#25809, #4410).
     *
     * php-src: zif_eval / zend_eval_string — "{parent}({call_line}) : eval()'d code".
     */
    public static function zendEvalFilename(string $parentFile, int $callLine): string
    {
        if ('' === $parentFile) {
            $parentFile = 'Command line code';
        }
        if ($callLine > 0) {
            return $parentFile.'('.$callLine.') : '.self::EVAL_FILENAME;
        }

        return $parentFile.' : '.self::EVAL_FILENAME;
    }

    public static function isEvalScriptPath(string $path): bool
    {
        return self::EVAL_FILENAME === $path
            || str_ends_with($path, self::EVAL_FILENAME);
    }

    /**
     * Declaring class (self) of an eval/include caller frame (#31912).
     *
     * php-src: execute_data->func->common.scope — not called_scope (that is {@see Frame::$calledClass}).
     */
    public static function declaringClassFromFrame(Frame $frame): ?string
    {
        $func = null !== $frame->block ? $frame->block->func : null;
        $fromFunc = self::declaringClassFromCfgFunc($func);
        if (null !== $fromFunc) {
            return $fromFunc;
        }
        if (null !== $frame->scopeClass && '' !== $frame->scopeClass) {
            return $frame->scopeClass;
        }

        return null;
    }

    /**
     * Declaring class of a CFG func / method block (JIT TYPE_EVAL caller) (#31912).
     */
    public static function declaringClassFromCfgFunc(?\PHPCfg\Func $func): ?string
    {
        if (null === $func || null === $func->class) {
            return null;
        }
        $value = $func->class->value ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    public static function declaringClassFromBlock(?Block $block): ?string
    {
        if (null === $block) {
            return null;
        }

        return self::declaringClassFromCfgFunc($block->func);
    }

    /**
     * Call-site line for nesting into zendEvalFilename (#25809).
     *
     * Inside an outer eval unit, CFG lines are still wrapEvalCode-shifted; unwrap so nested
     * `__FILE__` matches Zend (`…eval()'d code(1) : eval()'d code`).
     */
    public static function evalCallSiteLine(string $parentFile, int $callLine): int
    {
        if ($callLine <= 0) {
            return 0;
        }
        if (self::isEvalScriptPath($parentFile)) {
            return self::unwrapEvalLine($callLine);
        }

        return $callLine;
    }

    /**
     * Map wrapped `<?php\n` + eval body source line back to Zend eval-string line (#25809).
     *
     * {@see wrapEvalCode()} prepends one line so parser lines are +1 vs the eval string.
     */
    public static function unwrapEvalLine(int $wrappedLine): int
    {
        return $wrappedLine > 1 ? $wrappedLine - 1 : max(1, $wrappedLine);
    }

    /**
     * eval() Internal builtin — caller scope is the parent frame of the handler.
     *
     */
    public static function evalString(Frame $frame): Variable
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('eval() requires at least one argument');
        }
        $codeVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $codeVar->type) {
            throw new \LogicException('eval() expects a string argument');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eval() requires VM context');
        }
        $caller = VmScope::requireCaller($frame);

        return self::evalCodeInFrame($ctx->runtime->vm(), $caller, $codeVar->toString());
    }

    /**
     * Compile eval source for JIT inline lowering — SSOT for parse path (#10248, #4652).
     *
     * Pass Zend-shaped `$filename` (`parent(line) : eval()'d code`) when known so Reflection
     * provenance matches php-src (#26032). Defaults to bare EVAL_FILENAME.
     *
     * Returns null on parse/compile failure (caller assigns false like Zend eval parse errors).
     * Swallows {@see CompileFatal} — use {@see tryCompileBlockOrThrowCompileFatal()} when AOT/JIT
     * must surface reference-profile rejects instead of silently emitting false (#26169).
     */
    public static function tryCompileBlock(
        Runtime $runtime,
        string $code,
        ?string $filename = null,
        ?string $evalClassScope = null
    ): ?Block {
        try {
            return self::tryCompileBlockOrThrowCompileFatal($runtime, $code, $filename, $evalClassScope);
        } catch (CompileFatal) {
            return null;
        }
    }

    /**
     * Like {@see tryCompileBlock()}, but rethrows {@see CompileFatal}.
     *
     * AOT TYPE_EVAL lowering must not swallow final-plain-property (and similar) rejects into
     * emitFalse + continue — that printed `parsed_ok` while Zend exits 255 (#26169, re-#25535).
     */
    public static function tryCompileBlockOrThrowCompileFatal(
        Runtime $runtime,
        string $code,
        ?string $filename = null,
        ?string $evalClassScope = null
    ): ?Block {
        Runtime::clearLastParseFailure();
        $runtime->compiler->resetCompileAbortDetail();
        $wrapped = self::wrapEvalCode($code);
        $filename ??= self::EVAL_FILENAME;
        $compiler = $runtime->compiler;
        $compiler->pushEvalClassScope($evalClassScope);
        try {
            return $runtime->parseAndCompile($wrapped, $filename);
        } catch (CompileFatal $e) {
            throw $e;
        } catch (\CompileError $e) {
            // Typed class const (and similar) 8.2 rejects throw CompileError with a
            // parser-shaped message — surface as CompileFatal so JIT/AOT emit ParseError
            // with the Zend text instead of a generic "Parse error" (#31860).
            if (CompileFatal::isSyntaxParseErrorMessage($e->getMessage())) {
                throw new CompileFatal($filename, 1, $e->getMessage());
            }

            return null;
        } catch (\Throwable) {
            return null;
        } finally {
            $compiler->popEvalClassScope();
        }
    }

    /**
     * php-src zend_compile_string / zend_eval_stringl: length 0 is FAILURE, not a null result (#31914).
     *
     * zif_eval maps FAILURE to boolean false. Whitespace-only and ';' still compile and return null.
     */
    public static function isZeroLengthEvalSource(string $code): bool
    {
        return '' === $code;
    }

    /**
     * Zend zif_eval RETVAL_FALSE when zend_eval_stringl returns FAILURE (#31914).
     */
    public static function falseEvalFailureResult(): Variable
    {
        $false = new Variable();
        $false->bool(false);

        return $false;
    }

    /**
     * Shared compile+execute path for TYPE_EVAL and the eval() builtin.
     */
    public static function evalCodeInFrame(
        VM $vm,
        Frame $scopeFrame,
        string $code
    ): Variable {
        // Empty source never compiles (zend_compile_string length 0 → NULL → FAILURE).
        if (self::isZeroLengthEvalSource($code)) {
            return self::falseEvalFailureResult();
        }

        $ctx = $vm->context;
        $runtime = $ctx->runtime;

        Runtime::clearLastParseFailure();
        $runtime->compiler->resetCompileAbortDetail();
        $wrapped = self::wrapEvalCode($code);
        // Stamp Zend reflection/__FILE__ shape onto the compile unit (#26032, #25809).
        [$evalFile] = ExceptionSupport::evalFatalSite($scopeFrame, 1);

        $compiler = $runtime->compiler;
        $compiler->pushEvalClassScope(self::declaringClassFromFrame($scopeFrame));
        try {
            $block = $runtime->parseAndCompile($wrapped, $evalFile);
        } catch (\CompileError $e) {
            // Zend eval() turns parser syntax failures into catchable ParseError (zif_eval).
            // Reference-profile rejectors throw CompileFatal; typed class/trait/interface const
            // rejects on 8.2 throw CompileError via throwCompileError() with the same
            // "syntax error, …" text — both must be ParseError, not process Fatal (#22796, #31860).
            if (CompileFatal::isSyntaxParseErrorMessage($e->getMessage())) {
                $line = 1;
                if ($e instanceof CompileFatal && $e->sourceLine > 0) {
                    $line = $e->sourceLine > 1 ? $e->sourceLine - 1 : max(1, $e->sourceLine);
                } elseif ($e->getCode() > 0) {
                    $line = $e->getCode();
                }
                self::failEvalParse($ctx, $e->getMessage(), $line, $code);
            }
            throw $e;
        } catch (\Throwable $e) {
            // php-parser Error for modifier lists uses the same text as zend_throw_exception
            // (CompileError), not E_PARSE — rethrow as CompileError for eval catch (#25420, #25114).
            $message = self::stripParserLineSuffix($e->getMessage());
            if (self::isCatchableCompileErrorMessage($message)) {
                throw new \CompileError($message);
            }
            self::failEvalParse($ctx, $e->getMessage(), self::lineFromThrowable($e), $code);
        } finally {
            $compiler->popEvalClassScope();
        }

        if (null === $block) {
            $detail = $runtime->formatParseAndCompileNullDetail(null)
                ?? Runtime::getLastParseFailure()
                ?? 'Parse error';
            self::failEvalParse($ctx, $detail, 1, $code);
        }

        return $vm->executeEvalBlock($block, $scopeFrame);
    }

    /**
     * True when php-src emits this diagnostic via zend_throw_exception(zend_ce_compile_error)
     * (catchable during eval), not zend_error_noreturn(E_COMPILE_ERROR) (#25114).
     *
     * php-src: Zend/zend_compile.c — zend_add_member_modifier / zend_modifier_list_to_flags.
     * Inheritance and most other compile diagnostics stay uncatchable (#22922).
     */
    public static function isCatchableCompileError(\CompileError $error): bool
    {
        return self::isCatchableCompileErrorMessage($error->getMessage());
    }

    /**
     * Message-only form for php-parser Error remapping before materializing ParseError (#25420).
     */
    public static function isCatchableCompileErrorMessage(string $message): bool
    {
        $message = self::stripParserLineSuffix($message);
        if (self::MULTIPLE_ACCESS_MODIFIERS_MESSAGE === $message) {
            return true;
        }
        // "Multiple readonly modifiers are not allowed", etc.
        if (1 === preg_match('/^Multiple [A-Za-z_]+ modifiers are not allowed$/', $message)) {
            return true;
        }
        // php-src zend_throw_exception(CompileError): abstract final members (#25114) and
        // `final` on promoted ctor params under PROFILE≤8.4 (#28481, Zend/zend_compile.c) —
        // "Cannot use the final modifier on an abstract …" / "… on a parameter".
        if (str_starts_with($message, 'Cannot use the final modifier on a')) {
            return true;
        }

        return false;
    }

    /** php-parser appends " on line N"; Zend CompileError messages do not. */
    private static function stripParserLineSuffix(string $message): string
    {
        $message = trim($message);
        if (1 === preg_match('/^(.*) on line \d+$/', $message, $m)) {
            return trim($m[1]);
        }

        return $message;
    }

    /**
     * Zend eval() parse failures throw ParseError (php-src zif_eval / zend_eval_string, #4410).
     *
     * @return never
     */
    private static function failEvalParse(
        Context $ctx,
        string $detail,
        int $evalLine,
        ?string $code = null
    ): void {
        $message = self::normalizeParseMessage($detail, $code);
        self::recordParseError($ctx, $message, $evalLine);
        throw new \ParseError($message, $evalLine);
    }

    /**
     * Map php-parser diagnostics toward Zend scanner/parser wording (#26691, #27107).
     *
     * Public for AOT TYPE_EVAL emit of catchable ParseError (EvalRuntime) — same text as
     * {@see failEvalParse()} / VM.
     *
     * php-src: Zend/zend_language_scanner.l — check_nesting_at_end() / report_bad_nesting().
     */
    public static function normalizeParseMessage(string $detail, ?string $code = null): string
    {
        if (null !== $code) {
            $open = self::innermostUnclosedNestChar($code);
            if (null !== $open) {
                return "Unclosed '".$open."'";
            }
        }

        $message = trim($detail);
        if (str_starts_with(strtolower($message), 'parse error:')) {
            $message = trim(substr($message, strlen('Parse error:')));
        }
        $message = self::stripParserLineSuffix($message);
        if (str_starts_with(strtolower($message), 'syntax error,')) {
            return $message;
        }
        if (str_starts_with($message, 'Syntax error,')) {
            return 'syntax error,'.substr($message, strlen('Syntax error,'));
        }

        return $message;
    }

    /**
     * Innermost unclosed nest opener at EOF (Zend check_nesting_at_end; #26691).
     *
     * @return '{'|'['|'('|null
     */
    public static function innermostUnclosedNestChar(string $code): ?string
    {
        $stack = [];
        $len = strlen($code);
        $i = 0;
        while ($i < $len) {
            $ch = $code[$i];
            if ('/' === $ch && $i + 1 < $len) {
                $next = $code[$i + 1];
                if ('/' === $next) {
                    $nl = strpos($code, "\n", $i + 2);
                    $i = false === $nl ? $len : $nl + 1;
                    continue;
                }
                if ('*' === $next) {
                    $end = strpos($code, '*/', $i + 2);
                    $i = false === $end ? $len : $end + 2;
                    continue;
                }
            }
            if ('#' === $ch) {
                $nl = strpos($code, "\n", $i + 1);
                $i = false === $nl ? $len : $nl + 1;
                continue;
            }
            if ("'" === $ch || '"' === $ch) {
                $quote = $ch;
                ++$i;
                while ($i < $len) {
                    if ('\\' === $code[$i]) {
                        $i += 2;
                        continue;
                    }
                    if ($quote === $code[$i]) {
                        ++$i;
                        break;
                    }
                    ++$i;
                }
                continue;
            }
            if ('{' === $ch || '[' === $ch || '(' === $ch) {
                $stack[] = $ch;
                ++$i;
                continue;
            }
            if ('}' === $ch || ']' === $ch || ')' === $ch) {
                $want = '}' === $ch ? '{' : (']' === $ch ? '[' : '(');
                if ([] !== $stack && $want === $stack[\count($stack) - 1]) {
                    array_pop($stack);
                }
                ++$i;
                continue;
            }
            ++$i;
        }

        if ([] === $stack) {
            return null;
        }

        return $stack[\count($stack) - 1];
    }

    private static function recordParseError(Context $ctx, string $message, int $line): void
    {
        $ctx->errors->recordLastError(ErrorReporter::E_PARSE, $message, self::EVAL_FILENAME, $line);
    }

    /**
     * Zend parses eval strings as inline PHP; our PHPCfg pipeline expects a script TU (#3358).
     * Trailing expressions without a semicolon are wrapped in return (zend_eval_string parity).
     *
     * Skip the return prefix when nest delimiters are still open — otherwise php-parser reports
     * misleading "unexpected T_CLASS" instead of Zend's Unclosed '{' (#26691).
     */
    public static function wrapEvalCode(string $code): string
    {
        $trimmed = rtrim($code);
        // Whitespace-only (not length 0) compiles as an empty statement list → NULL.
        // Zero-length is FAILURE at evalCodeInFrame / EvalRuntime, not this wrap (#31914).
        if ('' === $trimmed) {
            return "<?php\n";
        }
        if (null !== self::innermostUnclosedNestChar($trimmed)) {
            return "<?php\n".$code;
        }
        if (!str_ends_with($trimmed, ';') && !str_ends_with($trimmed, '}')) {
            return "<?php\nreturn ".$trimmed.';';
        }

        return "<?php\n".$code;
    }

    private static function lineFromParserError(\PhpParser\Error $e): int
    {
        $line = $e->getStartLine();
        if ($line > 0) {
            return $line;
        }
        if (preg_match('/\bon line (\d+)\b/', $e->getMessage(), $m)) {
            return (int) $m[1];
        }

        return 1;
    }

    private static function lineFromThrowable(\Throwable $e): int
    {
        if ($e instanceof \PhpParser\Error) {
            return self::lineFromParserError($e);
        }
        if (preg_match('/\bon line (\d+)\b/', $e->getMessage(), $m)) {
            $line = (int) $m[1];

            return $line > 1 ? $line - 1 : 1;
        }

        return 1;
    }
}
