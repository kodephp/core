<?php
declare(strict_types=1);

/**
 * Kode Core 异常体系的根异常。
 * 所有本包抛出的异常都继承自它，便于上层统一捕获。
 */
namespace Kode\Core\Exceptions;

class CoreException extends \RuntimeException
{
}
