<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/** zip_open() — open zip archive (ext/zip/php_zip.c; #6370). */
final class zip_open extends ZipProceduralFunction
{
    public function __construct()
    {
        parent::__construct('zip_open');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'zip_open', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'zip_open', 1, 'filename');
        $handle = VmZipProcedural::zipOpen($filename);
        if (false === $handle) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    \sprintf('zip_open(): Unable to open file: %s', $filename),
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle, $frame->vmContext);
    }
}
