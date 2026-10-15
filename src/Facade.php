<?php
declare(strict_types=1);

/**
 * Facade —— 静态门面基类（桥接 kode/facade 3.2）。
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
 * 本类相对 kode/facade 的两点增强（真正的代理/反射逻辑全部来自 kode/facade）：
 *   1. 自动绑定：首次解析时自动把「门面类 → id()」注册到 FacadeProxy，无需手动 register。
 *   2. 协程隔离：运行在 Fiber/Swoole/Swow 等协程运行时下时，自动为每个门面开启
 *      kode/facade 3.2 的「上下文安全模式」，使每个执行单元（协程）拥有独立的
 *      门面实例缓存，避免跨协程互相污染。由 App::boot() 在检测到协程运行时后自动打开。
 */
namespace Kode\Core;

use Kode\Facade\Facade as KodeFacade;
use Kode\Facade\FacadeProxy;

abstract class Facade extends KodeFacade
{
    /**
     * 全局开关：是否为“后续首次解析的门面”自动开启上下文安全模式。
     *
     * 在 Fiber / Swoole / Swow 等协程运行时下应开启，使每个执行单元（协程）
     * 拥有独立的门面实例缓存，避免跨协程互相污染。
     * 由 App::boot() 在检测到协程运行时后自动打开（见 Bootstrap::startRuntime）。
     */
    private static bool $autoContextSafe = false;

    /** 已解析（首次 getInstance）过的门面类，用于 retroactive 批量开启 context-safe。 */
    /** @var array<class-string, true> */
    private static array $resolved = [];

    /**
     * 获取门面背后的真实实例。
     * 首次调用时自动把「当前门面类 → id()」注册到 FacadeProxy；
     * 只要全局处于自动上下文安全模式，就为本门面开启协程隔离（不看绑定先后）。
     */
    #[\Override]
    public static function getInstance(): object
    {
        if (!FacadeProxy::isBound(static::class)) {
            FacadeProxy::bind(static::class, static::id());
        }
        self::$resolved[static::class] = true;
        if (self::$autoContextSafe && !static::isContextSafeMode()) {
            static::enableContextSafeMode();
        }
        return parent::getInstance();
    }

    /**
     * 切换全局自动上下文安全模式（协程环境下建议开启）。
     * 开启时同步为「此前已解析过」的门面补上隔离，避免启动顺序造成漏网。
     */
    public static function setAutoContextSafe(bool $on = true): void
    {
        self::$autoContextSafe = $on;
        if ($on) {
            self::enableContextSafeForAll();
        }
    }

    /** 当前是否处于全局自动上下文安全模式。 */
    public static function isAutoContextSafe(): bool
    {
        return self::$autoContextSafe;
    }

    /**
     * 为所有已解析过的门面（含本次请求前已用过的）批量开启上下文安全模式。
     * 适用于「已经在运行、中途才决定进入协程」的场景。
     */
    public static function enableContextSafeForAll(): void
    {
        foreach (array_keys(self::$resolved) as $facade) {
            $facade::enableContextSafeMode();
        }
    }

    /** 复位门面状态（含自动上下文安全开关、解析记录与门面绑定），主要用于测试隔离。 */
    public static function resetAll(): void
    {
        self::$autoContextSafe = false;
        foreach (array_keys(self::$resolved) as $facade) {
            FacadeProxy::unbind($facade);
        }
        self::$resolved = [];
        parent::resetState();
    }
}
