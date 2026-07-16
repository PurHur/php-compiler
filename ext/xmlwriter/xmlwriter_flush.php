<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_flush() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_flush extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_flush');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'xmlwriter_flush', 1, 2);
        $entry = $this->writerArg($frame, 'xmlwriter_flush');
        $empty = true;
        if (isset($frame->calledArgs[1])) {
            $empty = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $result = VmXmlWriter::flush($entry, $empty);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (\is_string($result)) {
                $ret->string($result);
            } else {
                $ret->int((int) $result);
            }
        });
    }
}
