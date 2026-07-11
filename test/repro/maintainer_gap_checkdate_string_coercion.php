<?php
if (!checkdate('2', '29', '2020')) {
    exit(1);
}
if (!checkdate(2, 29, 2020)) {
    exit(1);
}
echo "ok\n";
