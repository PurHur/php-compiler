<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

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
