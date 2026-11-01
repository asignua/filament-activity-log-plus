<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Revert;

use RuntimeException;

/**
 * The revert cannot be applied (nothing to write, record gone). The message is user-facing.
 */
final class RevertException extends RuntimeException {}
