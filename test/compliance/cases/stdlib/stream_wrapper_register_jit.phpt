--TEST--
stdlib stream_wrapper_register() — JIT compile-time literal lowering (#3383)
--FILE--
<?php
class VarStream {
    public $position = 0;
    public $payload = '';
    public function stream_open($path, $mode, $options) {
        $this->payload = substr($path, 6);
        $this->position = 0;
        return true;
    }
    public function stream_read($count) {
        $ret = substr($this->payload, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }
    public function stream_eof() {
        return $this->position >= strlen($this->payload);
    }
}
var_export(stream_wrapper_register('var', 'VarStream'));
echo "\n";
var_export(stream_wrapper_register('var', 'VarStream'));
echo "\n";
echo file_get_contents('var://hello'), "\n";
stream_wrapper_unregister('var');
var_export(stream_wrapper_restore('var'));
echo "\n";
--EXPECT--
true
false
hello
true
