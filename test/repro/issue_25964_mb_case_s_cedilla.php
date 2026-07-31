<?php
// #25964 — Ş/ş + digraph titlecase vs Zend (ext/mbstring Utf8CaseMap)
echo mb_strtolower('IŞIK', 'UTF-8'), "\n";
echo mb_strtoupper('ışık', 'UTF-8'), "\n";
echo mb_convert_case('iŞık', MB_CASE_TITLE, 'UTF-8'), "\n";
echo mb_convert_case('ĳssel', MB_CASE_TITLE, 'UTF-8'), "\n";
echo mb_convert_case('Ǆabc', MB_CASE_TITLE, 'UTF-8'), "\n";
echo mb_convert_case('ş', MB_CASE_TITLE, 'UTF-8'), "\n";
