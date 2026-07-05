<?php

declare(strict_types=1);

/**
 * Maintainer repro: touch()/chmod() on custom stream wrappers dispatch stream_metadata (#8689).
 */

class MetaStreamWrapper
{
    /** @var list<array{0: int, 1: mixed}> */
    public static array $calls = [];

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        self::$calls[] = [$option, $value];

        return true;
    }
}

stream_wrapper_register('testmeta', MetaStreamWrapper::class);

$touchOk = touch('testmeta://foo');
$chmodOk = chmod('testmeta://foo', 0644);

if (!$touchOk || !$chmodOk) {
    echo 'fail: touch=' . var_export($touchOk, true) . ' chmod=' . var_export($chmodOk, true) . "\n";
    exit(1);
}

$options = array_column(MetaStreamWrapper::$calls, 0);
if (!in_array(STREAM_META_TOUCH, $options, true) || !in_array(STREAM_META_ACCESS, $options, true)) {
    echo 'fail: expected STREAM_META_TOUCH and STREAM_META_ACCESS, got ' . var_export($options, true) . "\n";
    exit(1);
}

echo "ok\n";
