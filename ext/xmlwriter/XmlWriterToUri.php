<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLWriter::toUri() — static URI factory (php-src zim_XMLWriter_toUri; #19606, #30818).
 *
 * PHP 8.4+ only — gated by {@see \PHPCompiler\CompilerVersion::supportsXmlWriterFactories()}.
 */
final class XmlWriterToUri extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('toUri');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('XMLWriter::toUri() requires VM context');
        $this->requireExactUserArgCount($frame, 'XMLWriter::toUri', 1, false);
        $uriVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $uriVar->type) {
            throw new \TypeError('XMLWriter::toUri(): Argument #1 ($uri) must be of type string');
        }
        $writer = VmXmlWriter::toUri($ctx, $uriVar->toString());
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($writer): void {
            $ret->object($writer);
        });
    }
}
