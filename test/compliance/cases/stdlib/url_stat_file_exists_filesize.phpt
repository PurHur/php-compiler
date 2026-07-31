--TEST--
stdlib file_exists()/filesize() via url_stat on user wrappers (#25973)
--FILE--
<?php
class UrlStatWrap {
    public $data = 'ABCDEFGH';
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        return true;
    }
    public function stream_read($count) {
        return '';
    }
    public function stream_eof() {
        return true;
    }
    public function url_stat($path, $flags) {
        return ['size' => strlen($this->data)];
    }
}
@stream_wrapper_unregister('urlstat');
stream_wrapper_register('urlstat', UrlStatWrap::class);
var_export(file_exists('urlstat://x'));
echo "\n";
echo filesize('urlstat://x'), "\n";
var_export(is_file('urlstat://x'));
echo "\n";
stream_wrapper_unregister('urlstat');
--EXPECT--
true
8
false
