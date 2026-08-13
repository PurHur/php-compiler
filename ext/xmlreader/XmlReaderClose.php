<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::close() — release reader (php-src ext/xmlreader/php_xmlreader.c; #6135). */
final class XmlReaderClose extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::close', 0);
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::close()'
        );
        VmXmlReader::close($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
            $ret->bool(true);
        });
    }
}
