<?php
/**
 * #25593 companion — AOT runtime of ob_get_clean (Reflection is VM/JIT; AOT method dispatch separate).
 */
$empty = ob_get_clean();
echo 'empty=', var_export($empty, true), "\n";
ob_start();
echo 'payload';
$got = ob_get_clean();
echo 'got=', var_export($got, true), "\n";
