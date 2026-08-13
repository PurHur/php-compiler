<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::outputMemory() — fetch memory buffer (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #6065). */
final class XmlWriterOutputMemory extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('outputMemory');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::outputMemory()');
        $this->requireAtMostUserArgCount($frame, 'XMLWriter::outputMemory', 1);
        $flush = true;
        if (isset($frame->calledArgs[1])) {
            $flushVar = $frame->calledArgs[1]->resolveIndirect();
            $flush = $flushVar->toBool();
        }
        $xml = VmXmlWriter::outputMemory($entry, $flush);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($xml): void {
            $ret->string($xml);
        });
    }
}
