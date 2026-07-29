<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM introspection for output rewrite vars (#9753).
 *
 * Not JIT-compiled; decodes {@see OutputRewriteVarsJitHelper} blob for web drivers.
 */
final class VmOutputRewriteVars
{
    private const RECORD_SEP = "\x1D";

    private const FIELD_SEP = "\x1E";

    /**
     * Last-wins map for introspection (web drivers / ResponseContext).
     *
     * @return array<string, string>
     */
    public static function list(): array
    {
        $vars = [];
        foreach (self::listPairs() as [$name, $value]) {
            $vars[$name] = $value;
        }

        return $vars;
    }

    /**
     * Ordered name/value pairs including duplicate names (php-src url_scanner append semantics, #24370).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function listPairs(): array
    {
        $blob = OutputRewriteVarsJitHelper::exportBlob();
        if ('' === $blob) {
            return [];
        }

        $pairs = [];
        foreach (\explode(self::RECORD_SEP, $blob) as $record) {
            if ('' === $record) {
                continue;
            }
            $fieldSep = \strpos($record, self::FIELD_SEP);
            if (false === $fieldSep) {
                continue;
            }
            $pairs[] = [
                \substr($record, 0, $fieldSep),
                \substr($record, $fieldSep + 1),
            ];
        }

        return $pairs;
    }
}
