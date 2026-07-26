<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeCdata() — CDATA section (php-src ext/xmlwriter/php_xmlwriter.c; #19340). */
final class XmlWriterWriteCData extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeCdata');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeCdata()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'XMLWriter::writeCdata() expects at least 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $content = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeCdata()', 0, 'content');
        $ok = VmXmlWriter::writeCData($entry, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
