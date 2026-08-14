--TEST--
streams stream_wrapper_register() optional $flags — 2–3 args ok, 4 ACE (#31069, main/streams/userspace.c)
--FILE--
<?php
class W {
    public int $position = 0;
    public string $payload = '';
    public function stream_open(string $path, string $mode, int $options): bool {
        $this->payload = substr($path, 6);
        $this->position = 0;
        return true;
    }
    public function stream_read(int $count): string {
        $ret = substr($this->payload, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }
    public function stream_eof(): bool {
        return $this->position >= strlen($this->payload);
    }
}
var_export(stream_wrapper_register('swr', W::class));
echo "\n";
var_export(stream_wrapper_register('sw2', W::class, 0));
echo "\n";
echo file_get_contents('sw2://ok'), "\n";
stream_wrapper_unregister('swr');
stream_wrapper_unregister('sw2');
try {
    stream_wrapper_register('sw3', W::class, 0, 'extra');
    echo "no_throw\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
ok
stream_wrapper_register() expects at most 3 arguments, 4 given
