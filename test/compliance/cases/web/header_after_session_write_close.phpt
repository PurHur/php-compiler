--TEST--
Web: header Location 303 after session_write_close in POST branch (005-SessionsWeb, #1887)
--FILE--
<?php
declare(strict_types=1);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ('POST' === $method) {
    session_start();
    $_SESSION['flash'] = (string) ($_POST['message'] ?? 'saved');
    session_write_close();
    header('Location: /example.php', true, 303);
    exit;
}
--ENV--
REQUEST_METHOD=POST
REQUEST_BODY=message=Saved
HTTP_CONTENT_TYPE=application/x-www-form-urlencoded
GATEWAY_INTERFACE=CGI/1.1
--EXPECT--
