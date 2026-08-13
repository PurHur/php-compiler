<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::setSchema() — php-src zim_XMLReader_setSchema (#19553). */
final class XmlReaderSetSchema extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('setSchema');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::setSchema', 1);
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        if (VmXmlReader::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError('XMLReader::setSchema(): Argument must be XMLReader, '.$entry->class->name.' given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('XMLReader::setSchema() requires VM context in this compiler build');
        }
        $filename = self::coerceNullablePath($frame->calledArgs[1]->resolveIndirect());
        $ok = VmXmlReader::setSchema($frame->vmContext, $entry, $filename, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    private static function coerceNullablePath(Variable $var): ?string
    {
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_STRING !== $var->type) {
            $label = Variable::TYPE_ARRAY === $var->type ? 'array' : (
                Variable::TYPE_OBJECT === $var->type ? 'object' : (
                    Variable::TYPE_INTEGER === $var->type ? 'int' : (
                        Variable::TYPE_BOOLEAN === $var->type ? 'bool' : 'unknown'
                    )
                )
            );
            throw new \TypeError(sprintf(
                'XMLReader::setSchema(): Argument #1 ($filename) must be of type ?string, %s given',
                $label
            ));
        }

        return $var->toString();
    }
}
