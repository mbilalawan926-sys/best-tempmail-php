<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * Base class for everything this package throws, so a caller who only wants
 * one catch block can have one.
 */
abstract class BestTempMailException extends \RuntimeException
{
}
