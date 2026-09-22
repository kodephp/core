<?php
declare(strict_types=1);

/**
 * 服务提供者抽象类（内置，桥接 kode/di）。
 *
 * 扩展 kode/di 的 ServiceProvider，复用其 bind / singleton / instance / alias / extend
 * 等能力；同时约定两个生命周期钩子：
 *
 *   - register()：在“注册服务”阶段被调用，向容器登记绑定（此时其它服务可能尚未就绪）。
 *   - boot()    ：在所有提供者的 register() 完成后被调用（此时依赖已全部可用）。
 *
 * 注：kode/di 的 provides() / isDeferred() / when() 属容器侧元数据，本包的启动序列
 * （ProviderRepository）恒为「全部提供者先 register() 再 boot()」两阶段 eager 驱动，
 * 不会据 isDeferred() 延后实例化；需要按需加载请在 register() 里用惰性闭包绑定。
 *
 * 这是 kode/core 内置于本包的 ServiceProvider 部分（对应“【必须自研】App启动器”中
 * 允许内置的 ServiceProvider）。
 */
namespace Kode\Core\Provider;

use Kode\DI\ServiceProvider as DiServiceProvider;

abstract class ServiceProvider extends DiServiceProvider
{
    /** 注册服务绑定（必须实现）。 */
    abstract public function register(): void;
}
