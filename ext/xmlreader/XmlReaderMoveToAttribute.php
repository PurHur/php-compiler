<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::moveToAttribute() — attribute cursor by name (#19395). */
final class XmlReaderMoveToAttribute extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('moveToAttribute');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('XMLReader::moveToAttribute() expects exactly 1 argument, 0 given');
        }
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::moveToAttribute()'
        );
        $nameVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \TypeError('XMLReader::moveToAttribute(): Argument #1 ($name) must be of type string');
        }
        $ok = VmXmlReader::moveToAttribute($entry, $nameVar->toString());
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
