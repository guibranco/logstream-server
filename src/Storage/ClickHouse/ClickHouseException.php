<?php

declare(strict_types=1);

namespace LogService\Storage\ClickHouse;

/** ClickHouse could not be reached, or answered a query with an error. */
final class ClickHouseException extends \RuntimeException
{
}
