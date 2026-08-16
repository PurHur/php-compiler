<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_start_dtd_entity() (php-src php_xmlwriter.c; #20322). */
final class xmlwriter_start_dtd_entity extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_start_dtd_entity');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_start_dtd_entity', 3);
        $entry = $this->writerArg($frame, 'xmlwriter_start_dtd_entity');
        $name = $this->stringArgAt($frame, 1, 'xmlwriter_start_dtd_entity', 2, 'name');
        $isParam = $frame->calledArgs[2]->resolveIndirect()->toBool();
        $ok = VmXmlWriter::startDtdEntity($entry, $name, $isParam);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
