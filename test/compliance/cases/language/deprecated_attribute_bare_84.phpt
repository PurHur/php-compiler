--TEST--
Language: bare #[\Deprecated] on functions/methods emits E_USER_DEPRECATED (#27825, Zend/zend_execute.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');

#[\Deprecated]
function bare_dep(): void {}

#[\Deprecated(message: "use other")]
function msg_dep(): void {}

class Box {
    #[\Deprecated]
    public function ping(): void {}
}

bare_dep();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "bare_fn\n" : "no_bare_fn\n";

msg_dep();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";

(new Box())->ping();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "bare_method\n" : "no_bare_method\n";
--EXPECT--
Function bare_dep() is deprecated
bare_fn
Function msg_dep() is deprecated, use other
Method Box::ping() is deprecated
bare_method
