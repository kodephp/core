<?php
declare(strict_types=1);

/**
 * Facade —— 静态门面基类（桥接 kode/facade）。
 *
 * 让任意已注册到 kode/di 容器的服务，都能以「静态调用」的方式访问，
 * 语法类似 Laravel 的 Facade：
 *
 *   final class Cache extends \Kode\Core\Facade
 *   {
 *       protected static function id(): string
 *       {
 *           return CacheInterface::class; // 容器中该服务的标识
 *       }
 *   }
 *
 *   Cache::get('foo');   // 等价于 app(CacheInterface::class)->get('foo')
 *
 * 原理：kode/facade 在首次静态调用时，按 id() 从容器解析出真实实例并缓存，
 * 后续调用直接转发。容器由 App::boot() 在启动时注入（见 Bootstrap）。
 *
 * 本类相对 kode/facade 唯一的增强：首次解析时自动把「门面类 → id()」绑定到
 * FacadeProxy，无需手动 register；真正的代理/反射逻辑全部来自 kode/facade。
 */
namespace Kode\Core;

use Kode\Facade\Facade as KodeFacade;
use Kode\Facade\FacadeProxy;

abstract class Facade extends KodeFacade
{
    /**
     * 获取门面背后的真实实例。
     * 首次调用时自动把「当前门面类 → id()」注册到 FacadeProxy，
     * 之后即可按容器中的服务标识解析。
     */
    public static function getInstance(): object
    {
        if (!FacadeProxy::isBound(static::class)) {
            FacadeProxy::bind(static::class, static::id());
        }
        return parent::getInstance();
    }
}
