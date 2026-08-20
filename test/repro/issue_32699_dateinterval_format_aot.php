<?php
/** Repro #32699 — thin AOT DateInterval::format must not return empty. */
echo (new DateInterval('P1D'))->format('%d'), "\n";
echo (new DateInterval('P2Y3M'))->format('%y-%m'), "\n";
