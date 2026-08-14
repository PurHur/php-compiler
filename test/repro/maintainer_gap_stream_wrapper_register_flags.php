<?php

declare(strict_types=1);

/** Issue #31069 — stream_wrapper_register() optional $flags (main/streams/userspace.c). */

class FlagsStream
{
    public int $position = 0;

    public string $payload = '';

    public function stream_open(string $path, string $mode, int $options): bool
    {
        $this->payload = substr($path, 6);
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $ret = substr($this->payload, $this->position, $count);
        $this->position += strlen($ret);

        return $ret;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->payload);
    }
}

if (!stream_wrapper_register('flg', FlagsStream::class, 0)) {
    echo "register failed\n";
    exit(1);
}
echo file_get_contents('flg://hello'), "\n";
stream_wrapper_unregister('flg');

try {
    stream_wrapper_register('flg', FlagsStream::class, 0, 'extra');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
