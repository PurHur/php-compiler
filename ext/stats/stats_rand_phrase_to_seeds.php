<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** stats_rand_phrase_to_seeds() — PECL RANLIB phrtsd (#29622). */
final class stats_rand_phrase_to_seeds extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_phrase_to_seeds');
    }

    protected function compute(Frame $frame): float|int|bool|array
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_phrase_to_seeds() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $phrase = $this->requireStringArg($frame, 0, 'phrase');

        return VmStatsRandlib::phrtsd($phrase);
    }

    private function requireStringArg(Frame $frame, int $index, string $label): string
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            // PECL convert_to_string_ex — coerce scalars
            if (Variable::TYPE_NULL === $var->type) {
                return '';
            }
            if (Variable::TYPE_INTEGER === $var->type) {
                return (string) $var->toInt();
            }
            if (Variable::TYPE_FLOAT === $var->type) {
                return (string) $var->toFloat();
            }
            if (Variable::TYPE_BOOLEAN === $var->type) {
                return $var->toBool() ? '1' : '';
            }
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type string, %s given',
                $this->getName(),
                $index + 1,
                $label,
                match ($var->type) {
                    Variable::TYPE_ARRAY => 'array',
                    Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }
            ));
        }

        return $var->toString();
    }
}
