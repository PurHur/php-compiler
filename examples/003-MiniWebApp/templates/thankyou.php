<?php

$name = 'friend';
if (isset($_REQUEST['name'])) {
    $name = $_REQUEST['name'];
}
?>
<h1>Thank you, <?php echo htmlspecialchars($name); ?></h1>
