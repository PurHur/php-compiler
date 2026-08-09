<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** MongoDB\BSON\toJSON() — PHP value → JSON document (#27875). */
final class ns_bson_toJSON extends Internal
{
    public function __construct()
    {
        parent::__construct('MongoDB\\BSON\\toJSON');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('MongoDB\\BSON\\toJSON() expects exactly 1 argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $php = self::variableToPhp($frame->calledArgs[0]->resolveIndirect());
        $frame->returnVar->string(VmMongodbTypes::toJson($php));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('MongoDB\\BSON\\toJSON() is VM-only in this compiler build (#27875)');
    }

    /** @return mixed */
    private static function variableToPhp(Variable $var)
    {
        $var = $var->resolveIndirect();

        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            Variable::TYPE_ARRAY => self::hashTableToPhp($var->toArray()),
            Variable::TYPE_OBJECT => new \stdClass(),
            default => null,
        };
    }

    /** @return array<mixed> */
    private static function hashTableToPhp(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$keyVar, $value]) {
            /** @var Variable $keyVar */
            /** @var Variable $value */
            if (Variable::TYPE_INTEGER === $keyVar->type) {
                $out[$keyVar->toInt()] = self::variableToPhp($value);
            } else {
                $out[$keyVar->toString()] = self::variableToPhp($value);
            }
        }

        return $out;
    }
}
