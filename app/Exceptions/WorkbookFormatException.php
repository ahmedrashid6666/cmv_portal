<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an uploaded workbook can be opened but is not in the layout the
 * importer understands (no recognizable header row, or no importable data
 * rows). The message is written for the end user and shown verbatim.
 */
class WorkbookFormatException extends RuntimeException
{
}
