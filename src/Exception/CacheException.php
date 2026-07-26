<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Exception;

class CacheException extends \RuntimeException implements \Psr\Cache\CacheException, \Psr\SimpleCache\CacheException {}
