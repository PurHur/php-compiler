<?php
// #27195 — AOT preg_match_all must fill $matches[0] and not abort on implode.
preg_match_all("/\w+/", "a b c", $m);
echo implode(",", $m[0] ?? []), "\n";
