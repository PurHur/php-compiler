<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\OpCode;
use PHPCompiler\Web\SourceBundler;

/**
 * Zend fatal / uncaught throw site — user script file + line (#13201, zend_exceptions.c).
 */
final class FatalSite
{
    /**
     * @return array{0: string, 1: int}
     */
    public static function userSite(Frame $frame): array
    {
        $file = ExceptionSupport::throwSiteFile($frame);
        if ($frame->returnSiteLine > 0) {
            return self::normalizeDisplaySite($file, $frame->returnSiteLine, $frame);
        }
        $line = $frame->callSiteLine;
        if ($line <= 0) {
            for ($f = $frame->parent; null !== $f; $f = $f->parent) {
                if ($f->returnSiteLine > 0) {
                    return self::normalizeDisplaySite($file, $f->returnSiteLine, $frame);
                }
                if ($f->callSiteLine > 0) {
                    $line = $f->callSiteLine;
                    break;
                }
            }
        }
        if ($line <= 0) {
            $line = self::lineFromOpcodes($frame);
        }

        return self::normalizeDisplaySite($file, $line, $frame);
    }

    /**
     * Walk the current block for the nearest opcode source line (shared with debug_backtrace).
     */
    public static function lineFromOpcodes(Frame $frame): int
    {
        if (null === $frame->block) {
            return 0;
        }
        $block = $frame->block;
        // pos points at the next opcode; warnings during the current opcode use pos-1 (#13436).
        $pos = $frame->pos > 0 ? $frame->pos - 1 : 0;
        if ($pos >= $block->nOpCodes) {
            $pos = max(0, $block->nOpCodes - 1);
        }
        for ($i = $pos; $i >= 0; --$i) {
            $op = $block->opCodes[$i] ?? null;
            if (null === $op) {
                continue;
            }
            if (
                OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type
            ) {
                $line = OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                    ? (int) ($op->arg2 ?? 0)
                    : (int) ($op->arg1 ?? 0);
                if ($line > 0) {
                    return $line;
                }
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type && null !== $op->arg2 && (int) $op->arg2 > 0) {
                return (int) $op->arg2;
            }
            if (OpCode::TYPE_ECHO === $op->type && null !== $op->arg2 && (int) $op->arg2 > 0) {
                return (int) $op->arg2;
            }
            if (OpCode::TYPE_PRINT === $op->type && null !== $op->arg3 && (int) $op->arg3 > 0) {
                return (int) $op->arg3;
            }
            if (null !== $op->sourceLocation && $op->sourceLocation->startLine > 0) {
                return $op->sourceLocation->startLine;
            }
        }

        return 0;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function normalizeDisplaySite(string $file, int $line, Frame $frame): array
    {
        if ($line <= 0) {
            return [$file, 0];
        }
        $compileSource = $frame->block?->compileSource();
        if (null === $compileSource || '' === $compileSource) {
            return [$file, $line];
        }
        $mapped = SourceBundler::mapBundledLine($compileSource, $line);
        if (null === $mapped) {
            return [$file, $line];
        }
        [$mappedFile, $mappedLine] = $mapped;
        if ('' !== $mappedFile) {
            $file = $mappedFile;
        }

        return [$file, $mappedLine];
    }
}
