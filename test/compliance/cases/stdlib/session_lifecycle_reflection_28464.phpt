--TEST--
session lifecycle Reflection bool/void returns (#28464, session.stub.php)
--FILE--
<?php
foreach ([
    'session_write_close',
    'session_commit',
    'session_abort',
    'session_reset',
    'session_unset',
    'session_register_shutdown',
] as $f) {
    $r = new ReflectionFunction($f);
    $t = $r->getReturnType();
    echo $f, ' => ', $t ? (string) $t : '(none)', "\n";
}
?>
--EXPECT--
session_write_close => bool
session_commit => bool
session_abort => bool
session_reset => bool
session_unset => bool
session_register_shutdown => void
