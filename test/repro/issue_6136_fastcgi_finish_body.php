<?php
/**
 * Repro #6136 — post-finish echo must not appear in CGI client body.
 *
 * Under FastCGI active + CgiDriver capture:
 *   body before finish → client
 *   echo after finish → discarded from HTTP body
 */
header('Content-Type: text/plain');
echo "body\n";
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
echo "after\n";
