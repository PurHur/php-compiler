<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::text() — text node content (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #6065 / #31610). */
final class XmlWriterText extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('text');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::text()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::text', 1);
        $content = $this->stringArg($frame->calledArgs[1], 'XMLWriter::text()', 0, $frame, 'content');
        $ok = VmXmlWriter::text($entry, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
