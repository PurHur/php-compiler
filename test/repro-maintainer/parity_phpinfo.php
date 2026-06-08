<?php
ob_start();
phpinfo(INFO_GENERAL);
$out = ob_get_clean();
echo str_contains($out, 'PHP Version') ? "phpinfo ok\n" : "phpinfo missing\n";
echo zend_version(), "\n";
phpcredits(CREDITS_GENERAL);
echo "credits_called\n";
