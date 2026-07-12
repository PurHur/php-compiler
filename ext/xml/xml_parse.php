<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** xml_parse() — feed parser; malformed XML records libxml errors (#3494, #6058). */
final class xml_parse extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_parse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError('xml_parse() expects 2 or 3 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_parse', 1);
        $dataArg = $frame->calledArgs[1]->resolveIndirect();
        $data = Variable::TYPE_STRING === $dataArg->type ? $dataArg->toString() : (string) $dataArg->toInt();

        $isFinal = true;
        if (3 === $argc) {
            $finalArg = $frame->calledArgs[2]->resolveIndirect();
            $isFinal = Variable::TYPE_NULL === $finalArg->type ? true : (bool) $finalArg->toInt();
        }

        $status = VmXml::parse(
            $frame->vmContext,
            $parser->id,
            $data,
            $isFinal,
            $frame,
            $parser
        );
        $frame->returnVar->int($status);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('xml_parse() is not JIT-lowered in this compiler build');
    }
}
