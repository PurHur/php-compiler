--TEST--
call_user_func* missing method → __call / __callStatic (#25747)
--FILE--
<?php
class CufMagicCall {
    public function __call($n, $a) {
        return 'c:' . $n . ':' . count($a) . ':' . ($a[0] ?? '');
    }
    public static function __callStatic($n, $a) {
        return 'cs:' . $n . ':' . count($a) . ':' . ($a[0] ?? '');
    }
}
$c = new CufMagicCall();
var_export(is_callable([$c, 'missing']));
echo "\n";
var_export(is_callable(['CufMagicCall', 'missing']));
echo "\n";
echo call_user_func([$c, 'missing']), "\n";
echo call_user_func(['CufMagicCall', 'missing']), "\n";
echo call_user_func([$c, 'missing'], 'x'), "\n";
echo call_user_func(['CufMagicCall', 'missing'], 'y'), "\n";
echo call_user_func_array([$c, 'missing'], ['a', 'b']), "\n";
echo call_user_func('CufMagicCall::missing', 'z'), "\n";
--EXPECT--
true
true
c:missing:0:
cs:missing:0:
c:missing:1:x
cs:missing:1:y
c:missing:2:a
cs:missing:1:z
