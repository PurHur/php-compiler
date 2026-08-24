<?php
mb_ereg_search_init('hello', 'zzz');
echo mb_ereg_search_pos() === false ? "pos_false\n" : "pos_bad\n";
echo mb_ereg_search_regs() === false ? "regs_false\n" : "regs_bad\n";
