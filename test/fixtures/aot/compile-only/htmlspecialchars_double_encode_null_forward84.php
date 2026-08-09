<?php
// AOT compile+run (#29445): null double_encode must lower via Z_PARAM_BOOL, not LogicException.
error_reporting(E_ALL & ~E_DEPRECATED);
$r = htmlspecialchars('a', ENT_QUOTES, 'UTF-8', null);
echo ($r === 'a') ? "ok\n" : "bad\n";
$r2 = htmlspecialchars('&amp;', ENT_QUOTES, 'UTF-8', null);
echo ($r2 === '&amp;') ? "ok\n" : "bad\n";
