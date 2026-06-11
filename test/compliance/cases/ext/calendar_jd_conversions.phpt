--TEST--
ext calendar JD conversion spine VM parity (issue #6759)
--FILE--
<?php
$jd = gregoriantojd(6, 6, 2026);
echo "jd=", $jd, "\n";
echo "greg=", jdtogregorian($jd), "\n";
echo "jul=", jdtojulian($jd), "\n";
echo "unix=", jdtounix($jd), "\n";
echo "from_unix=", unixtojd(1780704000), "\n";
echo "cal_to_jd=", cal_to_jd(CAL_GREGORIAN, 6, 6, 2026), "\n";
echo "round=", jdtogregorian(gregoriantojd(1, 1, 1970)), "\n";
try {
    unixtojd(-1);
    echo "bad\n";
} catch (ValueError $e) {
    echo "unix_neg\n";
}
try {
    jdtounix(1);
    echo "bad\n";
} catch (ValueError $e) {
    echo "jd_out_of_range\n";
}
?>
--EXPECT--
jd=2461198
greg=6/6/2026
jul=5/24/2026
unix=1780704000
from_unix=2461198
cal_to_jd=2461198
round=1/1/1970
unix_neg
jd_out_of_range
