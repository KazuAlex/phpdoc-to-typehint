<?php

/**
 * Must be converted.
 *
 * @return string|null
 */
function foo(): ?string
{
}

/**
 * Must be converted.
 *
 * @param \DateTime|null $a
 * @param int|null       $c
 * @param string[]|null  $d
 *
 * @return float
 */
function bar(?\DateTime $a, array $b, ?int $c, ?array $d, bool $e, callable $f = null): float
{
    return 0.0;
}

/**
 * Must not be modified (no params defined).
 */
function baz($a)
{
}

/**
 * Must be converted.
 *
 * @return bool[]|null
 */
function bazbaz(): ?array
{
}

/**
 * Must not be converted (already using type hints).
 *
 * @param int $a
 *
 * @return string
 */
function bat(int $a): string
{
}

/**
 * Must not be modified (incompatible types).
 *
 * @param int|string|null
 *
 * @return bool|int|null
 */
function foobar($a)
{
}

/**
 * Must not be converted (type hints take precedence over PHPDoc annotations).
 *
 * @param int|null $a
 *
 * @return string
 */
function foobaz(int $a): string
{
}

/**
 * Must not be converted (already using default value).
 *
 * @param int|null $a
 */
function foobat(int $a = null)
{
}
