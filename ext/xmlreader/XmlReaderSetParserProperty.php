<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::setParserProperty() — php-src zim_XMLReader_setParserProperty (#19553). */
final class XmlReaderSetParserProperty extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('setParserProperty');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::setParserProperty', 2);
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        if (VmXmlReader::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError('XMLReader::setParserProperty(): Argument must be XMLReader, '.$entry->class->name.' given');
        }
        $property = self::coerceInt($frame->calledArgs[1]->resolveIndirect(), 'property', 1);
        $value = self::coerceBool($frame->calledArgs[2]->resolveIndirect(), 'value', 2);
        $ok = VmXmlReader::setParserProperty($entry, $property, $value);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    private static function coerceInt(Variable $var, string $name, int $argNum): int
    {
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return $var->toInt();
            case Variable::TYPE_NULL:
                return 0;
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1 : 0;
            case Variable::TYPE_FLOAT:
                return (int) $var->toFloat();
            case Variable::TYPE_STRING:
                $s = $var->toString();
                if (is_numeric($s)) {
                    return (int) (float) $s;
                }
                throw new \TypeError(sprintf(
                    'XMLReader::setParserProperty(): Argument #%d ($%s) must be of type int, string given',
                    $argNum,
                    $name
                ));
            default:
                $label = Variable::TYPE_ARRAY === $var->type ? 'array' : (
                    Variable::TYPE_OBJECT === $var->type ? 'object' : 'unknown'
                );
                throw new \TypeError(sprintf(
                    'XMLReader::setParserProperty(): Argument #%d ($%s) must be of type int, %s given',
                    $argNum,
                    $name,
                    $label
                ));
        }
    }

    private static function coerceBool(Variable $var, string $name, int $argNum): bool
    {
        switch ($var->type) {
            case Variable::TYPE_BOOLEAN:
                return $var->toBool();
            case Variable::TYPE_INTEGER:
                return 0 !== $var->toInt();
            case Variable::TYPE_NULL:
                return false;
            case Variable::TYPE_FLOAT:
                return 0.0 !== $var->toFloat();
            case Variable::TYPE_STRING:
                $s = $var->toString();

                return '' !== $s && '0' !== $s;
            default:
                $label = Variable::TYPE_ARRAY === $var->type ? 'array' : (
                    Variable::TYPE_OBJECT === $var->type ? 'object' : 'unknown'
                );
                throw new \TypeError(sprintf(
                    'XMLReader::setParserProperty(): Argument #%d ($%s) must be of type bool, %s given',
                    $argNum,
                    $name,
                    $label
                ));
        }
    }
}
