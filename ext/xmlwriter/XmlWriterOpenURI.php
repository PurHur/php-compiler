<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::openUri() — file target (php-src ext/xmlwriter/php_xmlwriter.c; #6065). */
final class XmlWriterOpenURI extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('openUri');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::openUri()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('XMLWriter::openUri() expects at least 2 arguments, 1 given');
        }
        $uri = $this->stringArg($frame->calledArgs[1], 'XMLWriter::openUri()', 0, 'uri');
        $ok = VmXmlWriter::openURI($entry, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
