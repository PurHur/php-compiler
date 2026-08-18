<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\PathSupport;
use PHPLLVM\Value;

/**
 * highlight_file() — read file and emit syntax-highlighted HTML (VM: HighlightEngine, #4824).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30689; peer highlight_string #30723).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/url.c PHP_FUNCTION(highlight_file)
 */
final class highlight_file extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..2 (#30689; ext/standard/basic_functions.stub.php).
        $this->requireArgCountRange($frame, $this->getName(), 1, 2);
        self::run($frame, $this->getName());
    }

    public static function run(Frame $frame, string $functionName): void
    {
        $argc = \count($frame->calledArgs);
        // Z_PARAM_PATH — empty string: Zend warns "Failed opening '' for highlighting"
        // then ValueError (php-src url.c; #30514). Do not throw before the warning.
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::rejectNullString(
                $frame->calledArgs[0],
                $functionName,
                'filename',
                0,
                $frame
            );
        }
        $path = VmString::coercePathBuiltinArg($frame->calledArgs[0], $functionName, 0, 'filename');
        $return = false;
        if ($argc >= 2) {
            // Z_PARAM_BOOL: strict TypeError on null; else soft-null DEP+coerce (#31383).
            $return = VmHighlight::resolveReturnFlag($frame, $functionName);
        }
        if ('' === $path) {
            VmStreamOpenFailure::warnHighlightFailedOpening($frame, $functionName, $path);
            throw new \ValueError(PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE);
        }
        if (VmStreamIncludeOpenPolicy::blockedForScriptOpen($path, $frame->vmContext)) {
            VmStreamIncludeOpenPolicy::warnScriptOpenBlocked($frame, $functionName, $path, true);
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->bool(false);

            return;
        }
        $contents = VmFs::readPathContentsViaOpen($path, $frame->vmContext);
        if (false === $contents) {
            VmStreamOpenFailure::warnFailedToOpen($frame, $functionName, $path);
            VmStreamOpenFailure::warnHighlightFailedOpening($frame, $functionName, $path);
            if (null === $frame->returnVar) {
                return;
            }
            if ($return) {
                $frame->returnVar->bool(false);

                return;
            }
            $frame->returnVar->bool(false);

            return;
        }
        $result = VmHighlight::highlightString($contents, $return);
        if (null === $frame->returnVar) {
            return;
        }
        if ($return) {
            if (false === $result) {
                $frame->returnVar->bool(false);

                return;
            }
            $frame->returnVar->string((string) $result);

            return;
        }
        $frame->returnVar->bool((bool) $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30689; peer #30723 / #27763).
        if (!$this->requireArgCountRangeJit($context, $args, $this->getName(), 1, 2)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitHighlight::highlightFile($context, $this->getName(), ...$args);
    }
}
