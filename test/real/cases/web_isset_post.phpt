--TEST--
Web: isset() on $_POST form fields
--ENV--
REQUEST_BODY=email=user%40example.com
--FILE--
<?php
if (isset($_POST['email'])) {
    echo 'email=', $_POST['email'], "\n";
} else {
    echo "no email\n";
}
if (!isset($_POST['missing'])) {
    echo "missing ok\n";
}
--EXPECT--
email=user@example.com
missing ok
