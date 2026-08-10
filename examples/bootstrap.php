<?php
declare(strict_types=1);

/**
 * kode/core 启动演示。
 *
 * 运行：php examples/bootstrap.php
 *
 * 展示：配置加载 → ServiceProvider 注册 → DI（kode/di）自动注入
 *       → 运行时启用命令（桥接 kode/runtime）→ 协程/并发任务演示。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\Core\App;
use Kode\Core\Config\Config;
use Kode\Core\Provider\ServiceProvider;

// ---------- 1) 业务服务 ----------

final class Logger
{
    public function info(string $msg): void
    {
        echo "[log] {$msg}\n";
    }
}

final class Greeter
{
    // 构造函数依赖由 kode/di 容器自动注入（类型提示 + 可被解析即自动装配）
    public function __construct(
        private Logger $logger,
        private string $name,
    ) {
    }

    public function hello(): string
    {
        $this->logger->info("greet {$this->name}");
        return "Hello, {$this->name}!";
    }
}

// ---------- 2) 服务提供者（内置 ServiceProvider，桥接 kode/di） ----------

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 读取配置，把 Greeter 以单例形式绑定进容器
        $name = $this->container->make(Config::class)->get('app.name', 'Kode');
        $this->container->singleton(Greeter::class, fn() => new Greeter($this->container->make(Logger::class), $name));
    }
}

// ---------- 3) 启动 App ----------

$app = App::boot([
    'base_path'   => __DIR__,
    'config_path' => __DIR__ . '/config',
    'providers'   => [AppServiceProvider::class],
    'runtime'     => ['fiber'], // 启用命令：叠加 Fiber 协程运行时
]);

echo $app->make(Greeter::class)->hello() . PHP_EOL;
echo 'app.name = ' . $app->config('app.name') . PHP_EOL;
echo 'runtime enabled = ' . implode(', ', array_keys($app->runtime->enabled())) . PHP_EOL;
echo 'active runtime  = ' . $app->runtime->getName() . PHP_EOL;

// ---------- 4) 协程 / 并发任务演示（桥接 kode/runtime 当前激活的运行时） ----------

$results = $app->runtime->runTasks([
    fn() => 'task-1@' . $app->runtime->getName(),
    fn() => 'task-2@coroutine',
]);
echo 'runtime tasks = ' . implode(' | ', $results) . PHP_EOL;

// ---------- 5) 其它运行时模式的“启用命令”演示（优雅降级） ----------

foreach (['process', 'thread', 'swoole', 'swow'] as $mode) {
    try {
        $app->enableRuntime($mode);
        echo "enabled runtime: {$mode} (active = {$app->runtime->getName()})\n";
    } catch (\Kode\Core\Exceptions\RuntimeException $e) {
        echo "runtime [{$mode}] 未启用：{$e->getMessage()}\n";
    }
}

// ---------- 6) 静态门面（桥接 kode/facade） ----------

// 任意容器服务都能以静态方式访问：DemoApiFacade::get() 等价于 app(DemoApi::class)->get()
final class DemoApi
{
    public function __construct(public string $version = 'v1')
    {
    }
    public function get(): string
    {
        return $this->version . '-data';
    }
}

final class DemoApiFacade extends \Kode\Core\Facade
{
    protected static function id(): string
    {
        return DemoApi::class;
    }
}

$app->container->singleton(DemoApi::class, fn() => new DemoApi('v2'));
echo 'facade = ' . DemoApiFacade::get() . PHP_EOL; // 输出 v2-data

// ---------- 7) 协程/分布式上下文（桥接 kode/context） ----------

$ctx = $app->context();
$ctx->set('tenant_id', 42);
echo 'context.tenant_id = ' . $ctx->get('tenant_id') . PHP_EOL;

$traceId = $ctx->startTrace();            // 开启一条分布式链路
$headers = $ctx->toHeaders();             // 导出可跨节点透传的 HTTP 头
echo 'trace_id = ' . $traceId . PHP_EOL;
echo 'trace headers = ' . implode(', ', array_keys($headers)) . PHP_EOL;
$ctx->reset();

// ---------- 8) console 运行时模式（kode/runtime 3.x + kode/console） ----------

try {
    $app->enableRuntime('console');
    echo "enabled runtime: console (active = {$app->runtime->getName()})\n";
} catch (\Kode\Core\Exceptions\RuntimeException $e) {
    echo "runtime [console] 未启用：{$e->getMessage()}\n";
}

