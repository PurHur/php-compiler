<?php

declare(strict_types=1);

namespace PHPCompiler\Cfg;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;

/**
 * Bootstrap-safe PHPCfg sub-block access (issue #1492).
 *
 * Gen-0 self-host parser rejects variable property syntax (`$op->{$name}`).
 * Public CFG op properties are resolved via object cast instead.
 */
final class OpSubBlockAccess
{
    public static function propertyValue(Op $op, string $name): mixed
    {
        $props = (array) $op;

        return $props[$name] ?? null;
    }

    /**
     * @param callable(CfgBlock): void $walker
     */
    public static function walkSubBlocks(Op $op, callable $walker): void
    {
        foreach ($op->getSubBlocks() as $name) {
            $sub = self::propertyValue($op, $name);
            if ($sub instanceof CfgBlock) {
                $walker($sub);

                continue;
            }
            if (!is_array($sub)) {
                continue;
            }
            foreach ($sub as $block) {
                if ($block instanceof CfgBlock) {
                    $walker($block);
                }
            }
        }
    }

    /**
     * @param list<CfgBlock> $queue
     */
    public static function enqueueSubBlocks(Op $op, array &$queue): void
    {
        foreach ($op->getSubBlocks() as $name) {
            $sub = self::propertyValue($op, $name);
            if (null === $sub) {
                continue;
            }
            foreach (is_array($sub) ? $sub : [$sub] as $subBlock) {
                if ($subBlock instanceof CfgBlock) {
                    $queue[] = $subBlock;
                }
            }
        }
    }
}
