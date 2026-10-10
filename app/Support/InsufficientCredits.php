<?php

namespace App\Support;

use RuntimeException;

/** Số dư không đủ cho thao tác tính phí. */
class InsufficientCredits extends RuntimeException {}
