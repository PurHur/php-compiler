<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Frame;
use PHPCompiler\Runtime;
use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
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
     * Returns null on parse/compile failure (caller assigns false like Zend eval parse errors).
     */
    public static function tryCompileBlock(Runtime $runtime, string $code): ?Block
    {
        Runtime::clearLastParseFailure();
        $runtime->compiler->resetCompileAbortDetail();
        $wrapped = self::wrapEvalCode($code);

        try {
            return $runtime->parseAndCompile($wrapped, self::EVAL_FILENAME);
        } catch (\CompileError) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Shared compile+execute path for TYPE_EVAL and the eval() builtin.
     */
    public static function evalCodeInFrame(
        VM $vm,
        Frame $scopeFrame,
        string $code
    ): Variable {
        $ctx = $vm->context;
        $runtime = $ctx->runtime;

        Runtime::clearLastParseFailure();
        $runtime->compiler->resetCompileAbortDetail();
        $wrapped = self::wrapEvalCode($code);

        try {
            $block = $runtime->parseAndCompile($wrapped, self::EVAL_FILENAME);
        } catch (CompileFatal $e) {
            // Reference-profile syntax rejectors throw CompileFatal; Zend eval surfaces ParseError (#22796).
            if (CompileFatal::isSyntaxParseErrorMessage($e->getMessage())) {
                $line = $e->sourceLine > 1 ? $e->sourceLine - 1 : max(1, $e->sourceLine);
                self::failEvalParse($ctx, $e->getMessage(), $line);
            }
            throw $e;
        } catch (\CompileError $e) {
            throw $e;
        } catch (\Throwable $e) {
            self::failEvalParse($ctx, $e->getMessage(), self::lineFromThrowable($e));
        }

        if (null === $block) {
            $detail = $runtime->formatParseAndCompileNullDetail(null)
                ?? Runtime::getLastParseFailure()
                ?? 'Parse error';
            self::failEvalParse($ctx, $detail, 1);
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
        $message = trim($error->getMessage());
        if (self::MULTIPLE_ACCESS_MODIFIERS_MESSAGE === $message) {
            return true;
        }
        // "Multiple readonly modifiers are not allowed", etc.
        if (1 === preg_match('/^Multiple [A-Za-z_]+ modifiers are not allowed$/', $message)) {
            return true;
        }
        // "Cannot use the final modifier on an abstract method|property|class member"
        if (str_starts_with($message, 'Cannot use the final modifier on an abstract')) {
            return true;
        }

        return false;
    }

    /**
     * Zend eval() parse failures throw ParseError (php-src zif_eval / zend_eval_string, #4410).
     *
     * @return never
     */
    private static function failEvalParse(Context $ctx, string $detail, int $evalLine): void
    {
        self::recordParseError($ctx, $detail, $evalLine);
        throw new \ParseError(self::normalizeParseMessage($detail), $evalLine);
    }

    private static function normalizeParseMessage(string $detail): string
    {
        $message = trim($detail);
        if (str_starts_with(strtolower($message), 'parse error:')) {
            $message = trim(substr($message, strlen('Parse error:')));
        }
        if (str_starts_with(strtolower($message), 'syntax error,')) {
            return $message;
        }
        if (str_starts_with($message, 'Syntax error,')) {
            return 'syntax error,'.substr($message, strlen('Syntax error,'));
        }

        return $message;
    }

    private static function recordParseError(Context $ctx, string $message, int $line): void
    {
        $ctx->errors->recordLastError(ErrorReporter::E_PARSE, $message, self::EVAL_FILENAME, $line);
    }

    /**
     * Zend parses eval strings as inline PHP; our PHPCfg pipeline expects a script TU (#3358).
     * Trailing expressions without a semicolon are wrapped in return (zend_eval_string parity).
     */
    public static function wrapEvalCode(string $code): string
    {
        $trimmed = rtrim($code);
        if ('' === $trimmed) {
            return "<?php\n";
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
