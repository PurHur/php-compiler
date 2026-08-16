<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/** xmlwriter_open_uri() — allocate URI/file writer (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_open_uri extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_open_uri');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_open_uri', 1);
        $uri = $this->stringArgAt($frame, 0, 'xmlwriter_open_uri', 1, 'uri');
        $entry = $this->newWriter($frame);
        $ok = VmXmlWriter::openURI($entry, $uri);
        if (!$ok) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    'xmlwriter_open_uri(): Unable to resolve file path',
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($entry): void {
            $ret->object($entry);
        });
    }
}
