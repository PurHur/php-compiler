<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeRaw() — unescaped markup (php-src ext/xmlwriter/php_xmlwriter.c; #19371). */
final class XmlWriterWriteRaw extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeRaw');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeRaw()');
        $argc = \count($frame->calledArgs);
        if ($argc !== 2) {
            throw new \ArgumentCountError(
                'XMLWriter::writeRaw() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $content = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeRaw()', 0, 'content');
        $ok = VmXmlWriter::writeRaw($entry, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
