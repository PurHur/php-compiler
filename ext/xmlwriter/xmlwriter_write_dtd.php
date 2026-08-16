<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_dtd() (php-src php_xmlwriter.c; #20049). */
final class xmlwriter_write_dtd extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_dtd');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'xmlwriter_write_dtd', 2, 5);
        $entry = $this->writerArg($frame, 'xmlwriter_write_dtd');
        $name = $this->stringArgAt($frame, 1, 'xmlwriter_write_dtd', 2, 'name');
        $publicId = null;
        $systemId = null;
        $content = null;
        if (isset($frame->calledArgs[2])) {
            $publicId = $this->nullableStringArgAt($frame, 2,
                'xmlwriter_write_dtd',
                3,
                'publicId'
            );
        }
        if (isset($frame->calledArgs[3])) {
            $systemId = $this->nullableStringArgAt($frame, 3,
                'xmlwriter_write_dtd',
                4,
                'systemId'
            );
        }
        if (isset($frame->calledArgs[4])) {
            $content = $this->nullableStringArgAt($frame, 4,
                'xmlwriter_write_dtd',
                5,
                'content'
            );
        }
        $ok = VmXmlWriter::writeDtd($entry, $name, $publicId, $systemId, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
