<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::moveToNextAttribute() — advance attribute cursor (#19395). */
final class XmlReaderMoveToNextAttribute extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('moveToNextAttribute');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('XMLReader::moveToNextAttribute() expects exactly 0 arguments, 0 given');
        }
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::moveToNextAttribute()'
        );
        $ok = VmXmlReader::moveToNextAttribute($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
