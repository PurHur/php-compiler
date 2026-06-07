--TEST--
Language: #[\Deprecated] on enum declarations — case fetch emits E_USER_DEPRECATED (#6921)
--FILE--
<?php
ini_set('error_reporting', '32767');
set_error_handler(function (): bool {
    return true;
});

#[\Deprecated(message: 'Legacy enum', since: '8.4')]
enum Legacy { case A; }

enum Control { case A; }

Legacy::A;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "deprecated\n" : "no\n";

Control::A;
echo "after\n";
--EXPECT--
Enum Legacy is deprecated since 8.4, Legacy enum
deprecated
after
