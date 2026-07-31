<?php
declare(strict_types=1);
echo 'ext=', extension_loaded('mysqli') ? '1' : '0', "\n";
echo 'fn=', function_exists('mysqli_connect') ? '1' : '0', "\n";
echo 'cls=', class_exists('mysqli') ? '1' : '0', "\n";
