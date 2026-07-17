<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::moveToFirstAttribute() — attribute cursor to first attr (#19395). */
final class XmlReaderMoveToFirstAttribute extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('moveToFirstAttribute');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('XMLReader::moveToFirstAttribute() expects exactly 0 arguments, 0 given');
        }
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::moveToFirstAttribute()'
        );
        $ok = VmXmlReader::moveToFirstAttribute($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
