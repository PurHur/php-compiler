<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeAttribute() — attribute on open start tag (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19340). */
final class XmlWriterWriteAttribute extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeAttribute');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeAttribute()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::writeAttribute', 2);
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeAttribute()', 0, 'name');
        $value = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writeAttribute()', 1, 'value');
        $ok = VmXmlWriter::writeAttribute($entry, $name, $value);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
