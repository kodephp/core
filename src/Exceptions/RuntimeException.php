<?php
declare(strict_types=1);

/**
 * 运行时相关错误（如启用了当前环境不支持的运行时模式）时抛出。
 */
namespace Kode\Core\Exceptions;

class RuntimeException extends CoreException
{
}
