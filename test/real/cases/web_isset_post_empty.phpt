--TEST--
Web: isset() false when $_POST is empty
--FILE--
<?php
if (isset($_POST['name'])) {
    echo 'has name', "\n";
} else {
    echo "no name\n";
}
--EXPECT--
no name
