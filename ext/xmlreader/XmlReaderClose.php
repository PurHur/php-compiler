<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::close() — release reader (php-src ext/xmlreader/php_xmlreader.c; #6135). */
final class XmlReaderClose extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('XMLReader::close() expects at least 1 argument, 0 given');
        }
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
