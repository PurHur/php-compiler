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
     * @return Variable|false
     */
    public static function evalString(Frame $frame): Variable|false
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
     * Shared compile+execute path for TYPE_EVAL and the eval() builtin.
     *
     * @return Variable|false
     */
    public static function evalCodeInFrame(
        VM $vm,
        Frame $scopeFrame,
        string $code
    ): Variable|false {
        $ctx = $vm->context;
        $runtime = $ctx->runtime;

        Runtime::clearLastParseFailure();
        $runtime->compiler->resetCompileAbortDetail();
        $wrapped = self::wrapEvalCode($code);

        try {
            $block = $runtime->parseAndCompile($wrapped, self::EVAL_FILENAME);
        } catch (\Throwable $e) {
            self::recordParseError($ctx, $e->getMessage(), self::lineFromThrowable($e));

            return false;
        }

        if (null === $block) {
            $detail = $runtime->formatParseAndCompileNullDetail(null)
                ?? Runtime::getLastParseFailure()
                ?? 'Parse error';
            self::recordParseError($ctx, $detail, 1);

            return false;
        }

        return $vm->executeEvalBlock($block, $scopeFrame);
    }

    private static function recordParseError(Context $ctx, string $message, int $line): void
    {
        $ctx->errors->recordLastError(ErrorReporter::E_PARSE, $message, self::EVAL_FILENAME, $line);
    }

    /**
     * Zend parses eval strings as inline PHP; our PHPCfg pipeline expects a script TU (#3358).
     * Trailing expressions without a semicolon are wrapped in return (zend_eval_string parity).
     */
    private static function wrapEvalCode(string $code): string
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
