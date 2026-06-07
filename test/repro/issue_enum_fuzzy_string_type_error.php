<?php
enum E: string { case A = 'hello'; }
$p = 0;
try { similar_text(E::A, 'hello', $p); } catch (Throwable $e) { echo 'similar_text: ', $e::class, "\n"; }
try { str_word_count(E::A); } catch (Throwable $e) { echo 'str_word_count: ', $e::class, "\n"; }
try { metaphone(E::A); } catch (Throwable $e) { echo 'metaphone: ', $e::class, "\n"; }
try { soundex(E::A); } catch (Throwable $e) { echo 'soundex: ', $e::class, "\n"; }
