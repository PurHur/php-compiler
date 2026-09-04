<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\Config;

/**
 * Deterministic AOT link / codegen knobs (#36399).
 *
 * Complements {@see CompileTarget}'s fixed triple/`generic` CPU: link with a content-hashed
 * GNU build-id, honor SOURCE_DATE_EPOCH when set, and map PHP_COMPILER_OPT_LEVEL onto the
 * AOT TargetMachine when PHP_COMPILER_AOT_CODEGEN_OPT is unset.
 */
final class AotReproducibleBuild
{
    public const ENV_REPRODUCIBLE = 'PHP_COMPILER_REPRODUCIBLE';

    /**
     * Always emit a content-hashed GNU build-id so two identical inputs produce the same note
     * (and so release checksums are not scrambled by a missing or random id).
     *
     * @param bool $asWlPrefix true when the driver is clang/gcc (needs -Wl,)
     */
    public static function linkBuildIdFlag(bool $asWlPrefix): string
    {
        $arg = '--build-id=sha1';

        return $asWlPrefix ? ' -Wl,'.$arg.' ' : ' '.$arg.' ';
    }

    /**
     * When SOURCE_DATE_EPOCH is set (or PHP_COMPILER_REPRODUCIBLE=1 without an epoch),
     * export a stable epoch into the link environment for toolchains that honor it.
     *
     * @param array<string, string>|null $env
     *
     * @return array<string, string>|null
     */
    public static function applySourceDateEpochToEnv(?array $env): ?array
    {
        $epoch = self::sourceDateEpoch();
        if (null === $epoch) {
            return $env;
        }
        $out = $env ?? [];
        $out['SOURCE_DATE_EPOCH'] = $epoch;

        return $out;
    }

    /** Digits-only SOURCE_DATE_EPOCH, or null when unset / invalid. */
    public static function sourceDateEpoch(): ?string
    {
        $raw = getenv('SOURCE_DATE_EPOCH');
        if (false === $raw || '' === $raw) {
            $cfg = Config::getenv('SOURCE_DATE_EPOCH');
            $raw = is_string($cfg) ? $cfg : '';
        }
        if ('' === $raw && self::isReproducibleMode()) {
            // Fixed epoch when reproducible mode is on but no date was supplied.
            $raw = '1700000000';
        }
        if ('' === $raw || !ctype_digit($raw)) {
            return null;
        }

        return $raw;
    }

    public static function isReproducibleMode(): bool
    {
        $flag = Config::getenv(self::ENV_REPRODUCIBLE);

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * Sort string list for deterministic manifests / link argument lists (#36399).
     *
     * @param list<string> $items
     *
     * @return list<string>
     */
    public static function sortedStrings(array $items): array
    {
        $out = array_values($items);
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * TargetMachine codegen opt: explicit PHP_COMPILER_AOT_CODEGEN_OPT wins; else map
     * PHP_COMPILER_OPT_LEVEL 0–3; else OptNone (matches prior #36387 default).
     */
    public static function targetMachineOptLevel(): int
    {
        $optEnv = Config::getenv('PHP_COMPILER_AOT_CODEGEN_OPT');
        if (is_string($optEnv) && '' !== $optEnv) {
            return match (strtolower($optEnv)) {
                'less' => \PHPLLVM\Target::OPT_LEVEL_LESS,
                'default' => \PHPLLVM\Target::OPT_LEVEL_DEFAULT,
                'aggressive' => \PHPLLVM\Target::OPT_LEVEL_AGGRESSIVE,
                default => \PHPLLVM\Target::OPT_LEVEL_NONE,
            };
        }

        $raw = Config::getenv('PHP_COMPILER_OPT_LEVEL');
        if (false === $raw || null === $raw || '' === $raw) {
            return \PHPLLVM\Target::OPT_LEVEL_NONE;
        }
        if (!is_numeric($raw)) {
            return \PHPLLVM\Target::OPT_LEVEL_NONE;
        }

        return match ((int) $raw) {
            1 => \PHPLLVM\Target::OPT_LEVEL_LESS,
            2 => \PHPLLVM\Target::OPT_LEVEL_DEFAULT,
            3 => \PHPLLVM\Target::OPT_LEVEL_AGGRESSIVE,
            default => \PHPLLVM\Target::OPT_LEVEL_NONE,
        };
    }
}
