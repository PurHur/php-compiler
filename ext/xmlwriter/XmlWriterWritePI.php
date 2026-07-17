<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writePI() — one-shot processing instruction (php-src ext/xmlwriter/php_xmlwriter.c; #19371). */
final class XmlWriterWritePI extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writePI');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writePI()');
        $argc = \count($frame->calledArgs);
        if ($argc !== 3) {
            throw new \ArgumentCountError(
                'XMLWriter::writePI() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        $target = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writePI()', 0, 'target');
        $content = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writePI()', 1, 'content');
        $ok = VmXmlWriter::writePI($entry, $target, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
