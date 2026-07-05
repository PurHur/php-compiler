<?php

declare(strict_types=1);

echo mb_substr('αβγ', -2, null, 'UTF-8'), "\n";
echo mb_strcut('日本語テスト', -3, null, 'UTF-8'), "\n";
