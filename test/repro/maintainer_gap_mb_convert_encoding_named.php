<?php
declare(strict_types=1);
// Repro #16886 — mb_convert_encoding() to_encoding:/from_encoding: named params (ext/mbstring/mbstring.stub.php).
$latin1 = "\xE9"; // é in ISO-8859-1
$out = mb_convert_encoding($latin1, to_encoding: 'UTF-8', from_encoding: 'ISO-8859-1');
echo $out === "\xC3\xA9" ? "ok\n" : "fail hex=".bin2hex($out)."\n";
