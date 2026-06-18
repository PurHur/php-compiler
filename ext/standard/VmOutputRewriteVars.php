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
     * @return array<string, string>
     */
    public static function list(): array
    {
        $blob = OutputRewriteVarsJitHelper::exportBlob();
        if ('' === $blob) {
            return [];
        }

        $vars = [];
        foreach (\explode(self::RECORD_SEP, $blob) as $record) {
            if ('' === $record) {
                continue;
            }
            $fieldSep = \strpos($record, self::FIELD_SEP);
            if (false === $fieldSep) {
                continue;
            }
            $name = \substr($record, 0, $fieldSep);
            $value = \substr($record, $fieldSep + 1);
            $vars[$name] = $value;
        }

        return $vars;
    }
}
