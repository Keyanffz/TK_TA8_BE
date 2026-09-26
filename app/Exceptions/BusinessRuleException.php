<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Pelanggaran aturan bisnis yang pesannya aman ditampilkan ke pengguna.
 * Dirender sebagai 422 dengan kode BUSINESS_RULE.
 */
class BusinessRuleException extends RuntimeException {}
