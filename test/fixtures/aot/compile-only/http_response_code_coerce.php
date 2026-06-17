<?php
// Compile-only (#4454): http_response_code() must lower numeric-string coercion for AOT.
http_response_code("404");
echo http_response_code(), "\n";
http_response_code(null);
echo http_response_code() === 404 ? "ok\n" : "fail\n";
