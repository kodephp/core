<?php
declare(strict_types=1);

/**
 * 全局辅助函数（随 composer 自动加载）。
 * 仅在 App 已启动（App::boot() 之后）时才可用。
 */

use Kode\Core\App;

if (!function_exists('app')) {
    /**
     * 获取已启动的 App 实例，或解析一个服务。
     *   app()              → 返回 App 实例
     *   app(Greeter::class)→ 等价于 $app->make(Greeter::class)
     */
    function app(string $abstract = null, array $parameters = []): mixed
    {
        $instance = App::getInstance();
        if ($abstract === null) {
            return $instance;
        }
        return $instance->make($abstract, $parameters);
    }
}

if (!function_exists('config')) {
    /**
     * 读取配置。
     *   config()                  → 返回 Config 实例
     *   config('app.name')        → 读取 app.name
     *   config('app.debug', false)→ 带默认值
     */
    function config(string $key = null, mixed $default = null): mixed
    {
        $instance = App::getInstance();
        if ($key === null) {
            return $instance->config;
        }
        return $instance->config($key, $default);
    }
}

if (!function_exists('runtime')) {
    /**
     * 获取当前运行时管理器（Runtime）。
     *   runtime()               → 返回 Runtime 实例
     *   runtime()->enable('fiber') → 动态启用协程运行时
     *   runtime()->runTasks([...]) → 在当前运行时上跑并发任务
     */
    function runtime(): \Kode\Core\Runtime\Runtime
    {
        $instance = App::getInstance();
        if ($instance === null) {
            throw new \RuntimeException('App 尚未启动，无法获取 runtime（请先调用 App::boot()）');
        }
        return $instance->runtime;
    }
}
