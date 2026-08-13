<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::read() — advance pull cursor (php-src ext/xmlreader/php_xmlreader.c; #6135). */
final class XmlReaderRead extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('read');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::read', 0);
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::read()'
        );
        $ok = VmXmlReader::read($frame->vmContext, $entry, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
