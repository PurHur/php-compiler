<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::read() — advance pull cursor (php-src ext/xmlreader/php_xmlreader.c; #6135). */
final class XmlReaderRead extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('read');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('XMLReader::read() expects at least 1 argument, 0 given');
        }
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
