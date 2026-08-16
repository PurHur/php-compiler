<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writePi() — one-shot processing instruction (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19371). */
final class XmlWriterWritePI extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writePi');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writePi()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::writePi', 2);
        $target = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writePi()', 0, $frame, 'target');
        $content = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writePi()', 1, $frame, 'content');
        $ok = VmXmlWriter::writePI($entry, $target, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
