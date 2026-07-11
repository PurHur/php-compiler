--TEST--
stdlib json_decode() JSON_INVALID_UTF8_* reports JSON_ERROR_UTF8 (#14145)
--FILE--
<?php
json_decode("\xFF", flags: 2097152);
echo 'substitute:', json_last_error(), ':', json_last_error_msg(), "\n";
json_decode("\xFF", flags: 1048576);
echo 'ignore:', json_last_error(), ':', json_last_error_msg(), "\n";
?>
--EXPECT--
substitute:5:Malformed UTF-8 characters, possibly incorrectly encoded
ignore:5:Malformed UTF-8 characters, possibly incorrectly encoded
