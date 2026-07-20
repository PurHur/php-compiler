<?php
// #21314 AOT smoke — password_needs_rehash(null) soft-null (must not TypeError-abort)
$r = password_needs_rehash(null, PASSWORD_DEFAULT);
echo is_bool($r) ? "bool\n" : "bad\n";
