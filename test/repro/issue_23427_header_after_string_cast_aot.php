<?php
/**
 * #23427 — AOT: header(..., true, 303) after (string)$arr[$k] must not abort.
 *
 * Minimal SessionsWeb flash-redirect shape. Mid-function JitBoolArg BB diamonds
 * after the cast left thin AOT heap-corrupt (free(): invalid pointer / exit 134).
 *
 * ./phpc build -o /tmp/h23427 test/repro/issue_23427_header_after_string_cast_aot.php
 * /tmp/h23427 ; echo exit:$?   # expect 0 + Saved
 */
$a = ['message' => 'Saved'];
$m = (string) $a['message'];
header('Location: /example.php', true, 303);
echo $m;
