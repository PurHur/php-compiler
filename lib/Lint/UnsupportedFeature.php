<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

/**
 * Known compiler limitation with a stable message shape for users and CI.
 *
 * Format:
 *   phpc: unsupported: {feature} ({matrixRow}, #{issue}) — {alternative}
 *
 * @see https://github.com/PurHur/php-compiler/issues/36396
 */
final class UnsupportedFeature extends \LogicException
{
    public string $feature;
    public string $matrixRow;
    public int $issue;
    public string $alternative;

    public function __construct(
        string $feature,
        string $matrixRow,
        int $issue,
        string $alternative,
        ?\Throwable $previous = null
    ) {
        $this->feature = $feature;
        $this->matrixRow = $matrixRow;
        $this->issue = $issue;
        $this->alternative = $alternative;
        parent::__construct(self::format($feature, $matrixRow, $issue, $alternative), 0, $previous);
    }

    public static function format(
        string $feature,
        string $matrixRow,
        int $issue,
        string $alternative
    ): string {
        return 'phpc: unsupported: '.$feature
            .' ('.$matrixRow.', #'.$issue.') — '.$alternative;
    }

    /**
     * Look up a catalogued feature id and throw.
     *
     * @return never
     */
    public static function raise(string $featureId, ?string $featureOverride = null): void
    {
        $row = UnsupportedRegistry::feature($featureId);
        throw new self(
            $featureOverride ?? $row['feature'],
            $row['matrixRow'],
            $row['issue'],
            $row['alternative']
        );
    }
}
