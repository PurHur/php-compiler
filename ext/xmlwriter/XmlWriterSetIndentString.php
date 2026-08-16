<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::setIndentString() — indent unit string (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19340). */
final class XmlWriterSetIndentString extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('setIndentString');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::setIndentString()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::setIndentString', 1);
        $indentation = $this->stringArg(
            $frame->calledArgs[1], 'XMLWriter::setIndentString()', 0, $frame, 'indentation'
        );
        $ok = VmXmlWriter::setIndentString($entry, $indentation);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
