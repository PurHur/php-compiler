<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::next([string $name]) — skip to following sibling-level node (#19395).
 *
 * php-src zim_XMLReader_next / xmlTextReaderNext.
 */
final class XmlReaderNext extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'XMLReader::next', 0, 1);
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::next()'
        );
        $name = null;
        if (\count($frame->calledArgs) >= 2) {
            $nameVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL === $nameVar->type) {
                $name = null;
            } elseif (Variable::TYPE_STRING !== $nameVar->type) {
                throw new \TypeError('XMLReader::next(): Argument #1 ($name) must be of type ?string');
            } else {
                $name = $nameVar->toString();
            }
        }
        $ok = VmXmlReader::next($entry, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
