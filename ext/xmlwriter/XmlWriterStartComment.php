<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startComment() — open XML comment (php-src ext/xmlwriter/php_xmlwriter.c; #19386). */
final class XmlWriterStartComment extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startComment');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startComment()');
        $ok = VmXmlWriter::startComment($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
