<?php
class VarStream {
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
stream_wrapper_register('var', 'VarStream');
echo file_get_contents('var://hello'), "\n";
