--TEST--
Web: header Location 303 after stmt-level POST ?? (005-SessionsWeb POST branch, #10380)
--FILE--
<?php
$flash = (string) ($_POST['message'] ?? 'saved');
header('Location: /example.php', true, 303);
echo http_response_code(), "\n";
--EXPECT--
303
