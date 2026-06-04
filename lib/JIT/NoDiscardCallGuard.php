<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\JIT\Call;
use PHPCompiler\VM\ErrorReporter;

/**
 * Emit E_WARNING when JIT lowers a discarded call to a #[\NoDiscard] function (#5663, #5078).
 *
 * php-src: Zend/zend_execute.c zend_check_nodiscard()
 */
final class NoDiscardCallGuard
{
    public static function registerCallee(Context $context, string $logicalName, Block $block): void
    {
        if (!$block->noDiscard) {
            return;
        }
        $context->noDiscardCalleeMessages[strtolower($logicalName)] = $block->noDiscardMessage;
    }

    public static function emitAfterDiscardedReturn(Context $context, ?Call $toCall): void
    {
        if (!$toCall instanceof Call\Native) {
            return;
        }
        $lc = strtolower($toCall->name);
        if (!\array_key_exists($lc, $context->noDiscardCalleeMessages)) {
            return;
        }
        $meta = new NoDiscardMetadata($context->noDiscardCalleeMessages[$lc]);
        if (str_contains($toCall->name, '::')) {
            [$class, $method] = explode('::', $toCall->name, 2);
            $message = $meta->formatMethod($class, $method);
        } else {
            $message = $meta->formatFunction($toCall->name);
        }
        self::emitWarning($context, $message, $context->callSiteLine);
    }

    private static function emitWarning(Context $context, string $message, int $line): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(max(0, $line), false)
        );
    }
}
