<?php
/**
 * Repro #28473 — array_filter/array_reduce/array_walk excess/missing argc
 * must throw ArgumentCountError (not LogicException).
 * php-src: ext/standard/array.stub.php
 */
try { array_filter(); echo "array_filter/0:ok\n"; } catch (Throwable $e) { echo 'array_filter/0:', get_class($e), ':', $e->getMessage(), "\n"; }
try { array_filter([], null, 0, 1); echo "array_filter/4:ok\n"; } catch (Throwable $e) { echo 'array_filter/4:', get_class($e), ':', $e->getMessage(), "\n"; }
try { array_reduce(); echo "array_reduce/0:ok\n"; } catch (Throwable $e) { echo 'array_reduce/0:', get_class($e), ':', $e->getMessage(), "\n"; }
try { array_reduce([]); echo "array_reduce/1:ok\n"; } catch (Throwable $e) { echo 'array_reduce/1:', get_class($e), ':', $e->getMessage(), "\n"; }
try { array_reduce([], function ($a, $b) { return $a; }, 0, 1); echo "array_reduce/4:ok\n"; } catch (Throwable $e) { echo 'array_reduce/4:', get_class($e), ':', $e->getMessage(), "\n"; }
try { array_walk(); echo "array_walk/0:ok\n"; } catch (Throwable $e) { echo 'array_walk/0:', get_class($e), ':', $e->getMessage(), "\n"; }
$walkOne = [];
try { array_walk($walkOne); echo "array_walk/1:ok\n"; } catch (Throwable $e) { echo 'array_walk/1:', get_class($e), ':', $e->getMessage(), "\n"; }
try { array_walk($walkOne, function () {}, null, 1); echo "array_walk/4:ok\n"; } catch (Throwable $e) { echo 'array_walk/4:', get_class($e), ':', $e->getMessage(), "\n"; }
$a = [1, 2, 0, 3];
$filtered = array_filter($a);
echo 'array_filter_ok:', implode(',', $filtered), "\n";
$sum = array_reduce([1, 2, 3], function ($c, $v) { return $c + $v; }, 0);
echo 'array_reduce_ok:', (string) $sum, "\n";
$w = [1, 2];
array_walk($w, function (&$v) { $v *= 2; });
echo 'array_walk_ok:', implode(',', $w), "\n";
