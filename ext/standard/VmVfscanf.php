<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * vfscanf() stream scanf helper (php-src ext/standard/scanf.c; issue #6174).
 */
final class VmVfscanf
{
    /**
     * @param list<\PHPCompiler\VM\Variable> $outVars
     */
    public static function parse(int $handle, string $format, array $outVars): int
    {
        $start = VmFs::ftell($handle);
        if (false === $start) {
            return 0;
        }
        $data = VmFs::streamGetContents($handle, -1, -1);
        if (false === $data) {
            return 0;
        }
        [$assigned, $consumed] = VmSscanf::parseWithConsumed($data, $format, $outVars);
        if ($consumed > 0) {
            VmFs::fseek($handle, $start + $consumed, \SEEK_SET);
        }

        return $assigned;
    }
}
