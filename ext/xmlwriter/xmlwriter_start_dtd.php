<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_start_dtd() (php-src php_xmlwriter.c; #20322). */
final class xmlwriter_start_dtd extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_start_dtd');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'xmlwriter_start_dtd', 2, 4);
        $entry = $this->writerArg($frame, 'xmlwriter_start_dtd');
        $qualifiedName = $this->stringArgAt($frame, 1, 'xmlwriter_start_dtd', 2, 'qualifiedName');
        $publicId = null;
        $systemId = null;
        if (isset($frame->calledArgs[2])) {
            $publicId = $this->nullableStringArgAt($frame, 2,
                'xmlwriter_start_dtd',
                3,
                'publicId'
            );
        }
        if (isset($frame->calledArgs[3])) {
            $systemId = $this->nullableStringArgAt($frame, 3,
                'xmlwriter_start_dtd',
                4,
                'systemId'
            );
        }
        $ok = VmXmlWriter::startDtd($entry, $qualifiedName, $publicId, $systemId);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
