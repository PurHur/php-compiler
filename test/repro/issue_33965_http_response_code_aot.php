<?php
// AOT: http_response_code after Type::initialize always-on drop (#33965).
echo http_response_code(201), "\n";
echo http_response_code(), "\n";
