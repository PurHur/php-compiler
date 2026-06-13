<?php

declare(strict_types=1);

$s = '日本語テスト';
echo mb_strcut($s, 3, 2, 'UTF-8'), "\n";
echo mb_strcut($s, 0, 3, 'UTF-8'), "\n";
