<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * stream_select() preflight — warn on non-selectable streams (php-src streams.c; #10613).
 */
final class VmStreamSelectGuard
{
    public static function warnUnselectableStreams(Frame $frame, Variable $arrayArg): void
    {
        $arrayVar = $arrayArg->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arrayVar->type) {
            return;
        }
        foreach ($arrayVar->toArray()->iterateKeyed(true) as [, $streamVar]) {
            $streamVar = $streamVar->resolveIndirect();
            if (!$streamVar->isStreamResource()) {
                continue;
            }
            $handle = ResourceSupport::resolveHandle($streamVar);
            if (null === $handle) {
                continue;
            }
            $meta = VmFs::streamGetMetaData($handle);
            if (false === $meta) {
                continue;
            }
            $typeVar = $meta->find('stream_type');
            if (null === $typeVar) {
                continue;
            }
            if ('MEMORY' === $typeVar->resolveIndirect()->toString()) {
                self::warnMemoryNotSelectable($frame);
            }
        }
    }

    /** @param list<StreamSelectPair> ...$pairLists */
    public static function ensureSelectableStreamArrays(array ...$pairLists): void
    {
        $total = 0;
        foreach ($pairLists as $pairs) {
            $total += \count($pairs);
        }
        if (0 === $total) {
            throw new \ValueError('No stream arrays were passed');
        }
    }

    private static function warnMemoryNotSelectable(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            'stream_select(): Cannot represent a stream of type MEMORY as a select()able descriptor',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
