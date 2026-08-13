<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::setRelaxNGSchema() — php-src zim_XMLReader_setRelaxNGSchema (#19553). */
final class XmlReaderSetRelaxNGSchema extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('setRelaxNGSchema');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::setRelaxNGSchema', 1);
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        if (VmXmlReader::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError('XMLReader::setRelaxNGSchema(): Argument must be XMLReader, '.$entry->class->name.' given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('XMLReader::setRelaxNGSchema() requires VM context in this compiler build');
        }
        $filename = self::coerceNullablePath($frame->calledArgs[1]->resolveIndirect());
        $ok = VmXmlReader::setRelaxNGSchema($frame->vmContext, $entry, $filename, $frame);
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
                'XMLReader::setRelaxNGSchema(): Argument #1 ($filename) must be of type ?string, %s given',
                $label
            ));
        }

        return $var->toString();
    }
}
