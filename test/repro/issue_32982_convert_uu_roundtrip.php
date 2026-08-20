<?php
// AOT/VM convert_uuencode ↔ convert_uudecode round-trip (#32982 Type ABI shrink).
$data = "hello\nworld";
$enc = convert_uuencode($data);
$dec = convert_uudecode($enc);
echo ($dec === $data) ? "ok\n" : ("mismatch: ".var_export($dec, true)."\n");
