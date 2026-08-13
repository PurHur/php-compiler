<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::openUri() — file target (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #6065). */
final class XmlWriterOpenURI extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('openUri');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::openUri()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::openUri', 1);
        $uri = $this->stringArg($frame->calledArgs[1], 'XMLWriter::openUri()', 0, 'uri');
        $ok = VmXmlWriter::openURI($entry, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
