<?php
// Repro for #9306 — http_response_code() must return int, not ResponseCode enum.
var_dump(http_response_code(404));
var_dump(http_response_code());
var_dump(is_int(http_response_code()));
http_response_code(404);
var_dump(http_response_code(0));
