<?php
enum PathEnum: string { case X = 'nope'; }
try {
  dba_open(PathEnum::X, 'c', 'flatfile');
  echo "NO_THROW\n";
} catch (TypeError $e) {
  echo "TE=", (str_contains($e->getMessage(), 'PathEnum') || str_contains($e->getMessage(), 'string')) ? '1' : '0', "\n";
  echo "msg=", $e->getMessage(), "\n";
}
