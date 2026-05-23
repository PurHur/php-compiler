<?php
declare(strict_types=1);

echo preg_match('/^a/', 'abc') ? '1' : '0';
echo preg_quote('.+', '/');
echo preg_match('/b/', 'abc') ? '1' : '0';
