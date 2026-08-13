<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::setRelaxNGSchemaSource() — php-src zim_XMLReader_setRelaxNGSchemaSource (#19940). */
final class XmlReaderSetRelaxNGSchemaSource extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('setRelaxNGSchemaSource');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::setRelaxNGSchemaSource', 1);
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        if (VmXmlReader::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError('XMLReader::setRelaxNGSchemaSource(): Argument must be XMLReader, '.$entry->class->name.' given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('XMLReader::setRelaxNGSchemaSource() requires VM context in this compiler build');
        }
        $source = self::coerceNullableSource($frame->calledArgs[1]->resolveIndirect());
        $ok = VmXmlReader::setRelaxNGSchemaSource($frame->vmContext, $entry, $source, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    private static function coerceNullableSource(Variable $var): ?string
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
                'XMLReader::setRelaxNGSchemaSource(): Argument #1 ($source) must be of type ?string, %s given',
                $label
            ));
        }

        return $var->toString();
    }
}
