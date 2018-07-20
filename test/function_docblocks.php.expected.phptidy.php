<?php
/**
 * function_docblocks.php
 *
 * @package default
 * @see include.php
 */


/**
 *
 */
function x() {}


/**
 * another function
 */
function y() {
}


/**
 * comment1
 * comment2
 */
function z() {}


/** comment3 */
function a() {}


/* comment4 */


/**
 *
 */
function b() {}


/**
 * comment5
 */
function c() {}


/**
 *
 * @param unknown     $a
 * @param array       $b
 * @param MyClass     $c
 * @param unknown     $d (optional, reference)
 * @param array       $e (optional, reference)
 * @param MyClass     $f (reference)
 * @param unknown     $g (optional, reference)
 * @param array       $h (optional, reference)
 * @param MyLongClass $i (reference)
 */
function d($a, array $b, MyClass $c, &$d=0, array &$e=array(), MyClass &$f, & $g = 0, array & $h = array(), MyLongClass & $i) {}


/**
 *
 * @param unknown     $a___
 * @param array       $b    comment
 * @param MyClass     $c    comment
 * @param mixed       $d    (optional, reference)
 * @param array       $e    (optional, reference)
 * @param MyClass     $f    (reference) comment
 * @param unknown     $g_   (optional, reference)
 * @param array       $h    (optional, reference)
 * @param MyLongClass $i    (reference)
 */
function e($a___, array $b, MyClass $c, &$d=0, array &$e=array(), MyClass &$f, & $g_ = 0, array & $h = array(), MyLongClass & $i) {}
