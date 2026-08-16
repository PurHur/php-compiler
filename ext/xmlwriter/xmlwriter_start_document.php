<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_start_document() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_start_document extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_start_document');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'xmlwriter_start_document', 1, 4);
        $entry = $this->writerArg($frame, 'xmlwriter_start_document');
        $version = '1.0';
        if (isset($frame->calledArgs[1])) {
            $version = $this->nullableStringArgAt($frame, 1,
                'xmlwriter_start_document',
                2,
                'version'
            ) ?? '1.0';
        }
        $encoding = null;
        if (isset($frame->calledArgs[2])) {
            $encoding = $this->nullableStringArgAt($frame, 2,
                'xmlwriter_start_document',
                3,
                'encoding'
            );
        }
        $ok = VmXmlWriter::startDocument($entry, $version, $encoding);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
