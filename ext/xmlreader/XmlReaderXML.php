<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::XML() — in-memory open (php-src ext/xmlreader/php_xmlreader.c; #19308, #30563, #30641).
 *
 * Static call returns XMLReader|false; instance call mutates $this and returns bool.
 * Z_PARAM_STR: weak null → E_DEPRECATED then '' → ValueError; strict null → TypeError.
 * Arity: 1–3 user args (source [, encoding [, flags]]) — Zend ArgumentCountError (#30641).
 */
final class XmlReaderXML extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('XML');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('XMLReader::XML() requires VM context');
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError(
                XmlReaderClassMethod::atLeastUserArgCountMessage('XMLReader::XML', 1, 0)
            );
        }

        $first = $frame->calledArgs[0]->resolveIndirect();
        $instanceCall = Variable::TYPE_OBJECT === $first->type
            && VmXmlReader::CLASS_LC === strtolower($first->toObject()->class->name);

        if ($instanceCall) {
            $this->requireUserArgCountRange($frame, 'XMLReader::XML', 1, 3, true);
            // Z_PARAM_STR $source — frame index includes $this (#30563).
            $source = VmString::internalMethodStringArgForFrame(
                $frame,
                1,
                'XMLReader::XML',
                0,
                'source'
            );
            if ('' === $source) {
                throw new \ValueError('XMLReader::XML(): Argument #1 ($source) cannot be empty');
            }
            $ok = VmXmlReader::xmlOnto($ctx, $first->toObject(), $source, $frame);
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
                $ret->bool($ok);
            });

            return;
        }

        $this->requireUserArgCountRange($frame, 'XMLReader::XML', 1, 3, false);
        $source = VmString::internalMethodStringArgForFrame(
            $frame,
            0,
            'XMLReader::XML',
            0,
            'source'
        );
        if ('' === $source) {
            throw new \ValueError('XMLReader::XML(): Argument #1 ($source) cannot be empty');
        }
        $reader = VmXmlReader::xml($ctx, $source, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($reader): void {
            if (null === $reader) {
                $ret->bool(false);
            } else {
                $ret->object($reader);
            }
        });
    }
}
