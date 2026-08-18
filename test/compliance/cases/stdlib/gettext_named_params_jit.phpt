--TEST--
stdlib gettext family JIT — Zend stub names + named args (#24375, ext/gettext/gettext.stub.php)
--FILE--
<?php
foreach (['gettext', 'ngettext', 'dgettext', 'dngettext', 'bindtextdomain'] as $f) {
    $names = [];
    foreach ((new ReflectionFunction($f))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ': ', implode(' ', $names), "\n";
}
echo gettext(message: 'x'), "\n";
echo ngettext(singular: 'item', plural: 'items', count: 2), "\n";
echo dgettext(domain: 'messages', message: 'World'), "\n";
echo dngettext(domain: 'messages', singular: 'a', plural: 'b', count: 1), "\n";
$dir = bindtextdomain(domain: 'messages', directory: '/tmp');
echo is_string($dir) ? "bind-ok\n" : "bind-fail\n";
try {
    gettext(msgid: 'x');
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
gettext: message
ngettext: singular plural count
dgettext: domain message
dngettext: domain singular plural count
bindtextdomain: domain directory
x
items
World
a
bind-ok
Unknown named parameter $msgid
