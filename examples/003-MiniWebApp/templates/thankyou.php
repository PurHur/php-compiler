<?php

$name = $_POST['name'] ?? $_REQUEST['name'] ?? 'friend';
?>
<h1>Thank you, <?php echo htmlspecialchars($name); ?></h1>
