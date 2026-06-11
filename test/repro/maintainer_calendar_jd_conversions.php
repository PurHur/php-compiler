<?php

$jd = gregoriantojd(6, 6, 2026);
echo 'jd=', $jd, "\n";
echo 'greg=', jdtogregorian($jd), "\n";
echo 'jul=', jdtojulian($jd), "\n";
echo 'unix=', jdtounix($jd), "\n";
echo 'today_jd=', unixtojd(1717632000), "\n";
echo 'cal_to_jd=', cal_to_jd(CAL_GREGORIAN, 6, 6, 2026), "\n";
