<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_dtd_entity() (php-src php_xmlwriter.c; #20322). */
final class xmlwriter_write_dtd_entity extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_dtd_entity');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'xmlwriter_write_dtd_entity', 3, 7);
        $entry = $this->writerArg($frame, 'xmlwriter_write_dtd_entity');
        $name = $this->stringArgAt($frame, 1, 'xmlwriter_write_dtd_entity', 2, 'name');
        $content = $this->stringArgAt($frame, 2, 'xmlwriter_write_dtd_entity', 3, 'content');
        $isParam = false;
        $publicId = null;
        $systemId = null;
        $notationData = null;
        if (isset($frame->calledArgs[3])) {
            $isParam = $frame->calledArgs[3]->resolveIndirect()->toBool();
        }
        if (isset($frame->calledArgs[4])) {
            $publicId = $this->nullableStringArgAt($frame, 4,
                'xmlwriter_write_dtd_entity',
                5,
                'publicId'
            );
        }
        if (isset($frame->calledArgs[5])) {
            $systemId = $this->nullableStringArgAt($frame, 5,
                'xmlwriter_write_dtd_entity',
                6,
                'systemId'
            );
        }
        if (isset($frame->calledArgs[6])) {
            $notationData = $this->nullableStringArgAt($frame, 6,
                'xmlwriter_write_dtd_entity',
                7,
                'notationData'
            );
        }
        $ok = VmXmlWriter::writeDtdEntity(
            $entry,
            $name,
            $content,
            $isParam,
            $publicId,
            $systemId,
            $notationData
        );
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
