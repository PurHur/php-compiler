--TEST--
Web: header Location 303 named response_code after POST ?? (#10380)
--FILE--
<?php
$flash = (string) ($_POST['message'] ?? 'saved');
header('Location: /example.php', response_code: 303);
echo http_response_code(), "\n";
--EXPECT--
303
