<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

/** The gateway said no (payment not completed, more than was paid, ...). The message is safe to show to staff. */
class RefundRefused extends RuntimeException {}
