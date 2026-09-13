<?php

declare(strict_types=1);

namespace App\Native;

use RuntimeException;

/**
 * A native allocation or mapping refused an operation, or an access was
 * caught before it left the region it was allowed to touch.
 *
 * Worth noticing what this class cannot represent: the errors it exists to
 * prevent. A read past the end of a mapping is a SIGBUS and a write through a
 * freed pointer is silent corruption - neither raises anything PHP can catch,
 * which is why the checks happen before the call rather than around it.
 */
final class NativeMemoryException extends RuntimeException
{
}
