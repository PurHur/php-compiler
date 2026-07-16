<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_set_indent() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_set_indent extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_set_indent');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_set_indent', 2);
        $entry = $this->writerArg($frame, 'xmlwriter_set_indent');
        $enable = $frame->calledArgs[1]->resolveIndirect()->toBool();
        $ok = VmXmlWriter::setIndent($entry, $enable);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
