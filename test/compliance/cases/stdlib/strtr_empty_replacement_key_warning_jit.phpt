--TEST--
stdlib strtr() empty replace_pairs key emits E_WARNING (JIT) (#26704, ext/standard/string.c)
--FILE--
<?php
error_reporting(E_ALL);
$warns = [];
set_error_handler(static function (int $no, string $msg) use (&$warns): bool {
    $warns[] = $no . ':' . $msg;
    return true;
});
$out = strtr('ab', ['' => 'x', 'a' => 'A']);
echo 'out=' . $out . "\n";
echo 'warns=' . json_encode($warns) . "\n";

restore_error_handler();
error_clear_last();
@strtr('ab', ['' => 'x', 'a' => 'A']);
$e = error_get_last();
echo 'at_type=' . (null === $e ? 'null' : (string) $e['type']) . "\n";
echo 'at_msg=' . (null !== $e && str_contains((string) $e['message'], 'Ignoring replacement of empty string') ? 'yes' : 'no') . "\n";
?>
--EXPECT--
out=Ab
warns=["2:strtr(): Ignoring replacement of empty string"]
at_type=2
at_msg=yes
