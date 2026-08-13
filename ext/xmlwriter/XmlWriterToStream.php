<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLWriter::toStream() — static stream factory (php-src zim_XMLWriter_toStream; #19606, #30818).
 *
 * PHP 8.4+ only — gated by {@see \PHPCompiler\CompilerVersion::supportsXmlWriterFactories()}.
 */
final class XmlWriterToStream extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('toStream');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('XMLWriter::toStream() requires VM context');
        $this->requireExactUserArgCount($frame, 'XMLWriter::toStream', 1, false);
        $streamVar = $frame->calledArgs[0]->resolveIndirect();
        $writer = VmXmlWriter::toStream($ctx, $streamVar);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($writer): void {
            $ret->object($writer);
        });
    }
}
