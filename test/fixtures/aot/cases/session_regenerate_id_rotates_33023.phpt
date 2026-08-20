--TEST--
AOT: session_regenerate_id() rotates session_id() (#33023)
--FILE--
<?php
session_start();
$old = session_id();
$ok = session_regenerate_id(false);
$new = session_id();
echo 'ret=', $ok ? '1' : '0', "\n";
echo 'changed=', ($old !== $new) ? 'yes' : 'no', "\n";
echo 'lena=', strlen($old), "\n";
echo 'lenb=', strlen($new), "\n";
--EXPECTF--
Set-Cookie: PHPSESSID=%s; path=/
Set-Cookie: PHPSESSID=%s; path=/
ret=1
changed=yes
lena=26
lenb=26
