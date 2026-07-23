<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Shared extract_* wiring (PECL mailparse mailparse_do_extract; #22230).
 */
final class MailparseExtract
{
    /**
     * @param 0|1 $isfile 0 = msg body string, 1 = filename
     */
    public static function execute(Frame $frame, string $function, int $decodeFlags, int $isfile): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at least 2 arguments, %d given',
                $function,
                $argc
            ));
        }
        $msg = VmMailparse::requireMsgArg($frame->calledArgs[0], $function, 0);
        $callbackArg = $argc >= 3 ? $frame->calledArgs[2]->resolveIndirect() : null;
        $returnString = false;
        $onChunk = null;
        $echoStdout = false;

        if (null === $callbackArg) {
            // Omitted callback → stdout (PECL extract_callback_stdout).
            $echoStdout = true;
        } elseif (Variable::TYPE_NULL === $callbackArg->type) {
            $returnString = true;
        } elseif ($callbackArg->isStreamResource()) {
            $dest = VmStreamArg::requireStreamHandle($callbackArg, $function, 3);
            $onChunk = static function (string $chunk) use ($dest): void {
                \PHPCompiler\ext\standard\VmFs::fwrite($dest, $chunk);
            };
        } else {
            $ctx = $frame->vmContext;
            if (null === $ctx) {
                throw new \LogicException($function.'() requires a VM context');
            }
            $cb = $callbackArg;
            $onChunk = static function (string $chunk) use ($ctx, $cb, $function): void {
                $arg = new Variable(Variable::TYPE_STRING);
                $arg->string($chunk);
                VmCallable::invokeAs($function, $ctx, $cb, $arg);
            };
        }

        if (1 === $isfile) {
            $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $function, 1, 'filename');
            $result = VmMailparse::extractFromFile(
                $msg,
                $filename,
                $decodeFlags,
                $returnString,
                $onChunk
            );
        } else {
            // filename may be a stream resource of the full message (PECL) or string body.
            $src = $frame->calledArgs[1]->resolveIndirect();
            if ($src->isStreamResource()) {
                $handle = VmStreamArg::requireStreamHandle($src, $function, 2);
                \PHPCompiler\ext\standard\VmFs::fseek($handle, 0, \SEEK_SET);
                $data = '';
                while (!\PHPCompiler\ext\standard\VmFs::feof($handle)) {
                    $chunk = \PHPCompiler\ext\standard\VmFs::fread($handle, 8192);
                    if (false === $chunk || '' === $chunk) {
                        break;
                    }
                    $data .= $chunk;
                }
            } else {
                $data = VmString::coerceStringBuiltinArg($src, $function, 1, 'msgbody');
            }
            $result = VmMailparse::extract(
                $msg,
                $data,
                $decodeFlags,
                $returnString,
                $echoStdout ? null : $onChunk
            );
            // When echoStdout, extract() with onChunk=null and returnString=false echoes.
            if ($echoStdout && !$returnString && null === $onChunk) {
                // already handled inside extract via echo
            }
        }

        if (null === $frame->returnVar) {
            return;
        }
        if ($returnString) {
            if (\is_string($result)) {
                $frame->returnVar->string($result);
            } else {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $frame->returnVar->bool(false !== $result);
    }
}
