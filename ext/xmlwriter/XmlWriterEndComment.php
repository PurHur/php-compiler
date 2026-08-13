<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endComment() — close XML comment (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19386). */
final class XmlWriterEndComment extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endComment');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endComment()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::endComment', 0);
        $ok = VmXmlWriter::endComment($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
