<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Exception;

class InvalidArgumentException extends \InvalidArgumentException implements \Psr\Cache\InvalidArgumentException, \Psr\SimpleCache\InvalidArgumentException {}
