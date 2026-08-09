--TEST--
stdlib msg_* Reflection SysvMessageQueue stubs (#28452, ext/sysvmsg/sysvmsg.stub.php)
--SKIPIF--
<?php if (!function_exists('msg_get_queue')) { print 'skip sysvmsg unavailable'; } ?>
--FILE--
<?php
foreach (['msg_get_queue', 'msg_send', 'msg_receive', 'msg_remove_queue', 'msg_set_queue', 'msg_stat_queue'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $ref = $p->isPassedByReference() ? '&' : '';
        $opt = $p->isOptional() ? '=?' : '';
        $ps[] = $t . $ref . '$' . $p->getName() . $opt;
    }
    echo $fn, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
echo 'class=', class_exists('SysvMessageQueue') ? 'Y' : 'N', "\n";
?>
--EXPECT--
msg_get_queue(int $key, int $permissions=?): SysvMessageQueue|false
msg_send(SysvMessageQueue $queue, int $message_type, $message, bool $serialize=?, bool $blocking=?, &$error_code=?): bool
msg_receive(SysvMessageQueue $queue, int $desired_message_type, &$received_message_type, int $max_message_size, mixed &$message, bool $unserialize=?, int $flags=?, &$error_code=?): bool
msg_remove_queue(SysvMessageQueue $queue): bool
msg_set_queue(SysvMessageQueue $queue, array $data): bool
msg_stat_queue(SysvMessageQueue $queue): array|false
class=Y
