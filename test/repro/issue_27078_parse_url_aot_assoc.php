<?php
// #27078 — AOT parse_url() assoc string fields must match Zend (not zeros)
echo json_encode(parse_url("https://ex.com/a?b=1")), PHP_EOL;
$u = "https" . "://ex.com:8080/path?q=1#frag";
echo json_encode(parse_url($u)), PHP_EOL;
echo parse_url($u, PHP_URL_SCHEME), "|", parse_url($u, PHP_URL_PORT), PHP_EOL;
