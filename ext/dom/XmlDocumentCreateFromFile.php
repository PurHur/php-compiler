<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** Dom\XMLDocument::createFromFile() — VM (php-src ext/dom/xml_document.c; #20808). */
final class XmlDocumentCreateFromFile extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromFile');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Dom\\XMLDocument::createFromFile() requires VM context');
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('Dom\\XMLDocument::createFromFile() expects at least 1 argument, 0 given');
        }
        $path = $this->stringArg($frame->calledArgs[0], 'Dom\\XMLDocument::createFromFile()', 0, 'path');
        $options = 0;
        if (isset($frame->calledArgs[1])) {
            // Z_PARAM_LONG $options (#25768).
            $options = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'Dom\\XMLDocument::createFromFile',
                2,
                'options'
            );
        }
        $overrideEncoding = null;
        if (isset($frame->calledArgs[2])) {
            $encodingVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $encodingVar->type) {
                $overrideEncoding = $this->stringArg(
                    $frame->calledArgs[2],
                    'Dom\\XMLDocument::createFromFile()',
                    2,
                    'overrideEncoding'
                );
            }
        }
        $document = VmDomLiving::createXmlFromFile($ctx, $path, $options, $overrideEncoding, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($document): void {
            $ret->copyFrom($document);
        });
    }

    private function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_NULL !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d ($%s) to be of type string, %s given',
                $label,
                $index + 1,
                $paramName,
                VmDom::typeLabel($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }

        return $var->toString();
    }
}
