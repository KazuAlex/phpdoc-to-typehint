<?php

/**
 * @var string
 */
$foo = 'bar';

/**
 * Must not be modified.
 *
 * @return string|null
 */
function foo()
{
}

/**
 * Must be converted.
 *
 * @param int|null $c
 * @param string   $d
 *
 * @return float
 */
function bar(\DateTime $a = null, array $b, $c, $d, bool $e, callable $f = null)
{
    return 0.0;
}

/**
 * Must not be modified (no params defined).
 */
function baz($a)
{
}