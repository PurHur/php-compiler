<?php

declare(strict_types=1);

$funcs = ['jewishtojd', 'jdtojewish', 'jdtofrench', 'frenchtojd', 'juliantojd'];
foreach ($funcs as $fn) {
    echo $fn.':'.(function_exists($fn) ? 'y' : 'n')."\n";
}

$jd = jewishtojd(1, 1, 5781);
echo 'jewishtojd(1,1,5781)='.$jd."\n";
echo 'jdtojewish('.$jd.')='.jdtojewish($jd)."\n";

$fjd = frenchtojd(10, 20, 13);
echo 'frenchtojd(10,20,13)='.$fjd."\n";
echo 'jdtofrench('.$fjd.')='.jdtofrench($fjd)."\n";

echo 'juliantojd(1,1,2024)='.juliantojd(1, 1, 2024)."\n";
echo 'cal_to_jd(CAL_JEWISH,1,1,5781)='.cal_to_jd(CAL_JEWISH, 1, 1, 5781)."\n";
echo "ok\n";
