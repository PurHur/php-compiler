<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\spl\ArrayIteratorBuiltin;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOMTokenList algorithms in PHP (php-src ext/dom/token_list.c; issue #16876).
 */
final class VmDomTokenList
{
  private const ASCII_WHITESPACE = " \t\n\r\f\v";

  public static function elementClassValue(ObjectEntry $element): string
  {
    $state = DomRegistry::state($element);

    return $state->attributes['class'] ?? '';
  }

  /** @return list<string> */
  public static function parseTokens(string $value): array
  {
    $tokens = [];
    $position = 0;
    $length = \strlen($value);
    // Skip leading ASCII whitespace. Do not use `false !== strspn(...)`:
    // strspn returns 0 (not false) when the next char is not whitespace, which
    // made `false !== 0` spin forever on non-empty class values (#19605).
    $position += \strspn($value, self::ASCII_WHITESPACE, $position);
    while ($position < $length) {
      $run = \strcspn($value, self::ASCII_WHITESPACE, $position);
      if ($run > 0) {
        $token = \substr($value, $position, $run);
        if (!\in_array($token, $tokens, true)) {
          $tokens[] = $token;
        }
      }
      $position += $run;
      if ($position < $length) {
        $position += \strspn($value, self::ASCII_WHITESPACE, $position);
      }
    }

    return $tokens;
  }

  /** @param list<string> $tokens */
  public static function serializeTokens(array $tokens): string
  {
    if ([] === $tokens) {
      return '';
    }

    return \implode(' ', $tokens);
  }

  public static function ensureUpToDate(ObjectEntry $tokenList): void
  {
    if (!VmDom::isTokenList($tokenList)) {
      throw new \LogicException('VmDomTokenList::ensureUpToDate() called on non-token-list');
    }
    $listState = DomRegistry::state($tokenList);
    $elementId = $listState->tokenListElementId;
    if (null === $elementId) {
      return;
    }
    $element = DomRegistry::entry($elementId);
    if (null === $element || !VmDom::isElement($element)) {
      return;
    }
    $current = self::elementClassValue($element);
    if ($current === $listState->tokenListCachedClassValue) {
      return;
    }
    $listState->tokenListTokens = self::parseTokens($current);
    $listState->tokenListCachedClassValue = $current;
  }

  public static function invalidateForElement(ObjectEntry $element): void
  {
    if (!VmDom::isElement($element)) {
      return;
    }
    $state = DomRegistry::state($element);
    if (null === $state->classListId) {
      return;
    }
    $tokenList = DomRegistry::entry($state->classListId);
    if (null === $tokenList || !VmDom::isTokenList($tokenList)) {
      return;
    }
    DomRegistry::state($tokenList)->tokenListCachedClassValue = null;
  }

  public static function updateElement(Context $ctx, ObjectEntry $tokenList): void
  {
    self::ensureUpToDate($tokenList);
    $listState = DomRegistry::state($tokenList);
    $elementId = $listState->tokenListElementId;
    if (null === $elementId) {
      return;
    }
    $element = DomRegistry::entry($elementId);
    if (null === $element || !VmDom::isElement($element)) {
      return;
    }
    $serialized = self::serializeTokens($listState->tokenListTokens);
    if ('' === $serialized) {
      if (isset(DomRegistry::state($element)->attributes['class'])) {
        VmDom::removeAttributeNS($ctx, $element, null, 'class');
      }
      $listState->tokenListCachedClassValue = '';
    } else {
      VmDom::setAttributeNS($ctx, $element, null, 'class', $serialized);
      $listState->tokenListCachedClassValue = $serialized;
    }
  }

  public static function validateToken(string $token, int $argIndex): void
  {
    if (str_contains($token, "\0")) {
      throw new \ValueError(sprintf(
        'DOMTokenList::add(): Argument #%d ($tokens) must not contain any null bytes',
        $argIndex
      ));
    }
    if ('' === $token) {
      throw new \DOMException(
        'The empty string is not a valid token',
        DomExceptionConstants::SYNTAX_ERR
      );
    }
    if (false !== strpbrk($token, self::ASCII_WHITESPACE)) {
      throw new \DOMException(
        'The token must not contain any ASCII whitespace',
        DomExceptionConstants::INVALID_CHARACTER_ERR
      );
    }
  }

  /** @return list<string> */
  public static function collectTokenArgs(array $calledArgs, string $label): array
  {
    $tokens = [];
    $argc = \count($calledArgs);
    for ($i = 1; $i < $argc; ++$i) {
      $var = $calledArgs[$i]->resolveIndirect();
      if (Variable::TYPE_STRING !== $var->type) {
        throw new \TypeError(sprintf(
          '%s expects argument #%d to be of type string, %s given',
          $label,
          $i,
          VmDom::typeLabel($var)
        ));
      }
      $token = $var->toString();
      self::validateToken($token, $i);
      $tokens[] = $token;
    }

    return $tokens;
  }

  public static function add(Context $ctx, ObjectEntry $tokenList, array $calledArgs): void
  {
    $newTokens = self::collectTokenArgs($calledArgs, 'DOMTokenList::add()');
    self::ensureUpToDate($tokenList);
    $listState = DomRegistry::state($tokenList);
    foreach ($newTokens as $token) {
      if (!\in_array($token, $listState->tokenListTokens, true)) {
        $listState->tokenListTokens[] = $token;
      }
    }
    self::updateElement($ctx, $tokenList);
  }

  public static function remove(Context $ctx, ObjectEntry $tokenList, array $calledArgs): void
  {
    $removeTokens = self::collectTokenArgs($calledArgs, 'DOMTokenList::remove()');
    self::ensureUpToDate($tokenList);
    $listState = DomRegistry::state($tokenList);
    if ([] === $removeTokens) {
      return;
    }
    $listState->tokenListTokens = array_values(array_filter(
      $listState->tokenListTokens,
      static fn (string $token): bool => !\in_array($token, $removeTokens, true)
    ));
    self::updateElement($ctx, $tokenList);
  }

  public static function contains(ObjectEntry $tokenList, string $token): bool
  {
    self::validateToken($token, 1);
    self::ensureUpToDate($tokenList);

    return \in_array($token, DomRegistry::state($tokenList)->tokenListTokens, true);
  }

  public static function toggle(
    Context $ctx,
    ObjectEntry $tokenList,
    string $token,
    ?bool $force
  ): bool {
    self::validateToken($token, 1);
    self::ensureUpToDate($tokenList);
    $listState = DomRegistry::state($tokenList);
    $index = array_search($token, $listState->tokenListTokens, true);
    if (false !== $index) {
      if (null === $force || false === $force) {
        unset($listState->tokenListTokens[$index]);
        $listState->tokenListTokens = array_values($listState->tokenListTokens);
        self::updateElement($ctx, $tokenList);

        return false;
      }

      return true;
    }
    if (null === $force || true === $force) {
      $listState->tokenListTokens[] = $token;
      self::updateElement($ctx, $tokenList);

      return true;
    }

    return false;
  }

  public static function item(ObjectEntry $tokenList, int $index): ?string
  {
    self::ensureUpToDate($tokenList);
    $tokens = DomRegistry::state($tokenList)->tokenListTokens;
    if ($index < 0 || $index >= \count($tokens)) {
      return null;
    }

    return $tokens[$index];
  }

  public static function replace(
    Context $ctx,
    ObjectEntry $tokenList,
    string $token,
    string $newToken
  ): bool {
    self::validateToken($token, 1);
    self::validateToken($newToken, 2);
    self::ensureUpToDate($tokenList);
    $listState = DomRegistry::state($tokenList);
    $index = array_search($token, $listState->tokenListTokens, true);
    if (false === $index) {
      return false;
    }
    $existingIndex = array_search($newToken, $listState->tokenListTokens, true);
    if (false !== $existingIndex && $existingIndex !== $index) {
      unset($listState->tokenListTokens[$index]);
      $listState->tokenListTokens = array_values($listState->tokenListTokens);
    } else {
      $listState->tokenListTokens[$index] = $newToken;
    }
    self::updateElement($ctx, $tokenList);

    return true;
  }

  public static function length(ObjectEntry $tokenList): int
  {
    self::ensureUpToDate($tokenList);

    return \count(DomRegistry::state($tokenList)->tokenListTokens);
  }

  public static function value(ObjectEntry $tokenList): string
  {
    self::ensureUpToDate($tokenList);
    $elementId = DomRegistry::state($tokenList)->tokenListElementId;
    if (null === $elementId) {
      return '';
    }
    $element = DomRegistry::entry($elementId);
    if (null === $element) {
      return '';
    }

    return self::elementClassValue($element);
  }

  public static function setValue(Context $ctx, ObjectEntry $tokenList, string $value): void
  {
    if (str_contains($value, "\0")) {
      throw new \ValueError('Value must not contain any null bytes');
    }
    $elementId = DomRegistry::state($tokenList)->tokenListElementId;
    if (null === $elementId) {
      return;
    }
    $element = DomRegistry::entry($elementId);
    if (null === $element || !VmDom::isElement($element)) {
      return;
    }
    if ('' === $value) {
      if (isset(DomRegistry::state($element)->attributes['class'])) {
        VmDom::removeAttributeNS($ctx, $element, null, 'class');
      }
    } else {
      VmDom::setAttributeNS($ctx, $element, null, 'class', $value);
    }
    DomRegistry::state($tokenList)->tokenListCachedClassValue = null;
    self::ensureUpToDate($tokenList);
  }

  /**
   * Dom\TokenList::getIterator() — php-src returns InternalIterator over tokens (#20884).
   *
   * Expose ArrayIterator with index => token so foreach matches Zend (0=>a, 1=>b, …).
   */
  public static function getIterator(Context $ctx, ObjectEntry $tokenList): ObjectEntry
  {
    return self::iteratorOverTokens($ctx, $tokenList);
  }

  /**
   * Dom\TokenList::values() — same token sequence as getIterator() (#20884).
   */
  public static function values(Context $ctx, ObjectEntry $tokenList): ObjectEntry
  {
    return self::iteratorOverTokens($ctx, $tokenList);
  }

  /**
   * Dom\TokenList::keys() — iterator of token indices (#20884).
   */
  public static function keys(Context $ctx, ObjectEntry $tokenList): ObjectEntry
  {
    self::ensureUpToDate($tokenList);
    $ht = new HashTable();
    foreach (array_keys(DomRegistry::state($tokenList)->tokenListTokens) as $index) {
      $v = new Variable();
      $v->int($index);
      $ht->append($v);
    }

    return self::arrayIteratorFromTable($ctx, $ht);
  }

  /**
   * Dom\TokenList::entries() — iterator of [index, token] pairs (#20884).
   */
  public static function entries(Context $ctx, ObjectEntry $tokenList): ObjectEntry
  {
    self::ensureUpToDate($tokenList);
    $ht = new HashTable();
    foreach (DomRegistry::state($tokenList)->tokenListTokens as $index => $token) {
      $pair = new HashTable();
      $iVar = new Variable();
      $iVar->int($index);
      $pair->append($iVar);
      $tVar = new Variable();
      $tVar->string($token);
      $pair->append($tVar);
      $pairVar = new Variable();
      $pairVar->array($pair);
      $ht->append($pairVar);
    }

    return self::arrayIteratorFromTable($ctx, $ht);
  }

  /**
   * Dom\TokenList::forEach($callback, $thisArg = null) (#20884).
   *
   * WHATWG: callback(token, index, list). $thisArg is accepted for arity parity;
   * callbacks are invoked via {@see \PHPCompiler\ext\standard\VmCallable} (no JS this-binding).
   */
  public static function forEachTokens(
    Context $ctx,
    ObjectEntry $tokenList,
    Variable $callback
  ): void {
    self::ensureUpToDate($tokenList);
    $listVar = new Variable();
    $listVar->object($tokenList);
    foreach (DomRegistry::state($tokenList)->tokenListTokens as $index => $token) {
      $tokenVar = new Variable();
      $tokenVar->string($token);
      $indexVar = new Variable();
      $indexVar->int($index);
      \PHPCompiler\ext\standard\VmCallable::invokeAs(
        'Dom\\TokenList::forEach',
        $ctx,
        $callback,
        $tokenVar,
        $indexVar,
        $listVar
      );
    }
  }

  private static function iteratorOverTokens(Context $ctx, ObjectEntry $tokenList): ObjectEntry
  {
    self::ensureUpToDate($tokenList);
    $ht = new HashTable();
    foreach (DomRegistry::state($tokenList)->tokenListTokens as $token) {
      $v = new Variable();
      $v->string($token);
      $ht->append($v);
    }

    return self::arrayIteratorFromTable($ctx, $ht);
  }

  private static function arrayIteratorFromTable(Context $ctx, HashTable $ht): ObjectEntry
  {
    $class = $ctx->classes[ArrayIteratorBuiltin::CLASS_LC] ?? null;
    if (null === $class) {
      throw new \LogicException('ArrayIterator is not registered in this compiler build');
    }
    $entry = new ObjectEntry($class);
    $entry->constructed = true;
    ArrayIteratorBuiltin::init($entry, $ht);

    return $entry;
  }
}
