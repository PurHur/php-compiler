--TEST--
stdlib stream_wrapper_register() — custom protocol read via file_get_contents (#3383)
--FILE--
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
var_export(function_exists('stream_wrapper_register'));
echo "\n";
var_export(function_exists('stream_register_wrapper'));
echo "\n";
var_export(function_exists('stream_wrapper_unregister'));
echo "\n";
var_export(function_exists('stream_wrapper_restore'));
echo "\n";
var_export(function_exists('stream_get_wrappers'));
echo "\n";
if (!stream_wrapper_register('var', VarStream::class)) {
    echo "register failed\n";
    exit(1);
}
echo file_get_contents('var://hello'), "\n";
stream_wrapper_unregister('var');
var_export(in_array('var', stream_get_wrappers(), true));
echo "\n";
var_export(stream_wrapper_restore('var'));
echo "\n";
var_export(in_array('var', stream_get_wrappers(), true));
echo "\n";
--EXPECT--
true
true
true
true
true
hello
false
true
true
