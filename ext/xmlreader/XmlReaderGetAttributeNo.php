<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::getAttributeNo() — attribute value by index (#19412). */
final class XmlReaderGetAttributeNo extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributeNo');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::getAttributeNo', 1);
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::getAttributeNo()'
        );
        $index = self::coerceIndex($frame->calledArgs[1]->resolveIndirect());
        $value = VmXmlReader::getAttributeNo($entry, $index);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($value): void {
            if (null === $value) {
                $ret->null();
            } else {
                $ret->string($value);
            }
        });
    }

    /** Weak int coercion matching zend_parse_parameters("l") / php-src-strict. */
    private static function coerceIndex(Variable $var): int
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
                throw new \TypeError('XMLReader::getAttributeNo(): Argument #1 ($index) must be of type int, string given');
            default:
                $label = Variable::TYPE_ARRAY === $var->type ? 'array' : (
                    Variable::TYPE_OBJECT === $var->type ? 'object' : 'unknown'
                );
                throw new \TypeError(sprintf(
                    'XMLReader::getAttributeNo(): Argument #1 ($index) must be of type int, %s given',
                    $label
                ));
        }
    }
}
