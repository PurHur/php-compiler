<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * (array) cast lowering for VM (issue #3328, Zend cast_object / convert_to_array).
 */
final class CastSupport
{
    public static function toArray(Variable $src): Variable
    {
        $src = $src->resolveIndirect();
        $result = new Variable();

        if (Variable::TYPE_ARRAY === $src->type) {
            $result->array($src->toArray()->replaceCopy());

            return $result;
        }

        if (Variable::TYPE_OBJECT === $src->type) {
            $result->newArray();
            $ht = $result->toArray();
            foreach ($src->toObject()->getRawProperties() as $name => $prop) {
                $value = $prop->resolveIndirect();
                if (Variable::TYPE_NULL === $value->type) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $ht->add($name, $copy);
            }

            return $result;
        }

        if (Variable::TYPE_NULL === $src->type) {
            $result->newArray();

            return $result;
        }

        if (Variable::TYPE_BOOLEAN === $src->type && !$src->toBool()) {
            $result->newArray();

            return $result;
        }

        $result->newArray();
        $copy = new Variable();
        $copy->copyFrom($src);
        $result->toArray()->append($copy);

        return $result;
    }
}
