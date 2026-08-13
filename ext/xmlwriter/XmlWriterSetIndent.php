<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::setIndent() — pretty-print indent toggle (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19340). */
final class XmlWriterSetIndent extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('setIndent');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::setIndent()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::setIndent', 1);
        $enable = $frame->calledArgs[1]->resolveIndirect()->toBool();
        $ok = VmXmlWriter::setIndent($entry, $enable);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
