<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
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
        $path = VmStreamPath::coerceNonEmptyPathArgForFrame(
            $frame,
            0,
            $functionName,
            'filename'
        );
        $return = false;
        if ($argc >= 2) {
            $return = VmHighlight::resolveReturnFlag($frame->calledArgs[1], $functionName);
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
