<?php
declare(strict_types=1);

namespace Kode\Core\Tests;

use Kode\Core\App;
use Kode\Core\Config\Config;
use Kode\Core\Provider\ServiceProvider;
use Kode\DI\Attributes\Inject;
use Kode\DI\Container;
use PHPUnit\Framework\TestCase;

/**
 * kode/core 核心链路测试：启动 → 配置 → DI（kode/di）→ 服务提供者 → 运行时启用命令（桥接 kode/runtime）。
 * 注意：为避免 fork，本测试不启用 process 模式。
 */
final class AppTest extends TestCase
{
    /** 一个简单的服务，构造依赖 Logger（用于验证自动注入）。 */
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();

        // App::boot() 是单例，测试间需重置，避免上一个测试启动的实例污染本次断言
        $ref = new \ReflectionClass(App::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testBootLoadsConfigAndResolvesServices(): void
    {
        $app = App::boot([
            'config' => [
                'app' => ['name' => 'Tester', 'env' => 'test'],
            ],
            'providers' => [DemoProvider::class],
            'runtime' => ['fiber'],
        ]);

        // 配置加载
        self::assertSame('Tester', $app->config('app.name'));
        self::assertSame('test', $app->config('app.env'));

        // DI（kode/di）：Greeter 依赖 Logger 被自动注入，且为单例
        $greeter1 = $app->make(Greeter::class);
        $greeter2 = $app->make(Greeter::class);
        self::assertSame($greeter1, $greeter2);
        self::assertSame('Hello, Tester!', $greeter1->hello());

        // 运行时（桥接 kode/runtime）：默认 cli + 启用的 fiber
        self::assertTrue($app->runtime->has('cli'));
        self::assertTrue($app->runtime->has('fiber'));
    }

    public function testContainerAutowiresByType(): void
    {
        // DI 容器实现来自 kode/di
        $container = new Container();
        $container->instance(Logger::class, $this->logger);
        $obj = $container->make(NeedsLogger::class);

        self::assertInstanceOf(NeedsLogger::class, $obj);
        self::assertSame($this->logger, $obj->logger);
    }

    public function testConfigDotNotation(): void
    {
        $config = new Config();
        $config->set('a.b.c', 42);
        self::assertTrue($config->has('a.b.c'));
        self::assertSame(42, $config->get('a.b.c'));
        self::assertSame('default', $config->get('x.y', 'default'));
    }

    public function testRuntimeEnableUnknownThrows(): void
    {
        $app = App::boot([
            'config' => ['app' => ['name' => 'X']],
            'runtime' => [],
        ]);
        $this->expectException(\Kode\Core\Exceptions\RuntimeException::class);
        $app->enableRuntime('not-exist');
    }

    public function testAutoBridgeReportsMissingPackages(): void
    {
        $app = App::boot([
            'config' => ['app' => ['name' => 'X']],
            'runtime' => ['fiber'],
        ]);

        // 桥接能力自检：当前未安装对应包
        $bridges = $app->runtime->bridges();
        self::assertArrayHasKey('parallel', $bridges);
        self::assertArrayHasKey('distributed', $bridges);
        self::assertFalse($bridges['parallel']['installed']);
        self::assertFalse($bridges['distributed']['installed']);
        self::assertSame(['kode/parallel'], $bridges['parallel']['packages']);
        self::assertSame(['kode/fibers', 'kode/context'], $bridges['distributed']['packages']);

        // 未安装时启用 parallel：明确提示需 composer require
        try {
            $app->enableRuntime('parallel');
            self::fail('parallel 未安装时应抛异常');
        } catch (\Kode\Core\Exceptions\RuntimeException $e) {
            self::assertStringContainsString('composer require kode/parallel', $e->getMessage());
        }

        // 未安装时启用 distributed：明确提示需 composer require 两个包
        try {
            $app->enableRuntime('distributed');
            self::fail('distributed 未安装时应抛异常');
        } catch (\Kode\Core\Exceptions\RuntimeException $e) {
            self::assertStringContainsString('composer require kode/fibers kode/context', $e->getMessage());
        }
    }

    public function testAppVersionAndConvenienceMethods(): void
    {
        $app = App::boot([
            'config' => ['app' => ['name' => 'Helper']],
            'providers' => [DemoProvider::class],
            'runtime' => ['fiber'],
        ]);

        // 版本号常量
        self::assertSame('1.4.0', App::VERSION);

        // 全局辅助函数
        self::assertSame($app, app());
        self::assertSame('Helper', config('app.name'));
        self::assertInstanceOf(\Kode\Core\Runtime\Runtime::class, runtime());

        // 容器查询
        self::assertTrue($app->has(Greeter::class));
        self::assertFalse($app->has('App\\NoSuchService'));

        // DI 自动注入调用：回调参数按类型从容器解析
        $result = $app->call(fn (Greeter $g) => $g->hello());
        self::assertSame('Hello, Helper!', $result);

        // CLI 环境判定（PHPUnit 运行于 CLI）
        self::assertTrue($app->isCli());
    }

    public function testLifecycleEnvironmentAndPaths(): void
    {
        $app = App::boot([
            'base_path' => '/var/www/app',
            'config'    => ['app' => ['name' => 'Life', 'env' => 'testing']],
            'runtime'   => ['fiber'],
        ]);

        // 环境判定
        self::assertSame('testing', $app->environment());
        self::assertTrue($app->environment('testing'));
        self::assertTrue($app->environment('local', 'testing'));
        self::assertFalse($app->environment('production'));
        self::assertTrue($app->runningInConsole());

        // 路径辅助
        self::assertSame('/var/www/app', $app->basePath());
        self::assertSame('/var/www/app/config/app.php', $app->basePath('config/app.php'));
        self::assertSame('/var/www/app/src', $app->path());

        // 版本便捷方法
        self::assertSame('1.4.0', $app->version());

        // booted：应用已启动，注册即立即执行（并以 DI 注入 App）
        $bootedLog = [];
        $app->booted(function (App $a) use (&$bootedLog): void {
            $bootedLog[] = 'booted:' . $a->config('app.name');
        });
        self::assertSame(['booted:Life'], $bootedLog);

        // terminating：注册时不应执行，需手动触发；且幂等
        $termLog = [];
        $app->terminating(function () use (&$termLog): void {
            $termLog[] = 'terminating';
        });
        self::assertSame([], $termLog, 'terminating 不应在注册时执行');
        $app->callTerminatingCallbacks();
        self::assertSame(['terminating'], $termLog);
        $app->callTerminatingCallbacks(); // 再次调用应为空（已清空）
        self::assertSame(['terminating'], $termLog);
    }

    public function testEventsAndMaintenance(): void
    {
        // 用系统临时目录作为基路径，避免污染仓库
        $base = sys_get_temp_dir() . '/kode-core-test-' . uniqid();
        $app = App::boot([
            'base_path' => $base,
            'config'    => ['app' => ['env' => 'testing']],
            'runtime'   => ['fiber'],
        ]);

        // 事件总线
        self::assertFalse($app->hasListeners('demo.event'));
        $log = [];
        $listener = function (string $name) use (&$log): string {
            $log[] = "hi:$name";
            return strtoupper($name);
        };
        $app->listen('demo.event', $listener);
        self::assertTrue($app->hasListeners('demo.event'));

        $results = $app->dispatch('demo.event', 'kode');
        self::assertSame(['hi:kode'], $log);
        self::assertSame(['KODE'], $results);

        // 未注册事件返回空数组
        self::assertSame([], $app->dispatch('no.such.event'));

        // 移除指定监听器
        $app->forget('demo.event', $listener);
        self::assertFalse($app->hasListeners('demo.event'));

        // 维护模式
        self::assertFalse($app->isDownForMaintenance());
        $app->down('维护中');
        self::assertTrue($app->isDownForMaintenance());
        $app->up();
        self::assertFalse($app->isDownForMaintenance());

        // 单元测试环境判定（PHPUnit 下应为 true）
        self::assertTrue($app->runningUnitTests());

        // 清理临时目录
        @unlink($base . '/storage/framework/down');
        @rmdir($base . '/storage/framework');
        @rmdir($base . '/storage');
        @rmdir($base);
    }

    public function testFacadeStaticProxy(): void
    {
        $app = App::boot([
            'config'   => ['app' => ['name' => 'FacadeDemo']],
            'runtime'  => ['fiber'],
        ]);

        // 继承 Kode\Core\Facade 的静态门面，按 id() 从容器解析真实实例
        // DemoApiFacade::get() 等价于 app(DemoApi::class)->get()
        $app->container->singleton(DemoApi::class, fn() => new DemoApi('v1'));

        self::assertSame('v1-data', DemoApiFacade::get());
        // 门面应解析到与容器同一实例
        self::assertSame($app->make(DemoApi::class), DemoApiFacade::getInstance());
    }

    public function testContextWrapperAndHelper(): void
    {
        $app = App::boot([
            'config'  => ['app' => ['env' => 'testing']],
            'runtime' => ['fiber'],
        ]);

        // App::context() 返回的封装转发到 kode/context
        $ctx = $app->context();
        self::assertInstanceOf(\Kode\Core\Context::class, $ctx);

        $ctx->set('tenant_id', 42);
        self::assertTrue($ctx->has('tenant_id'));
        self::assertSame(42, $ctx->get('tenant_id'));

        // 链路追踪：开启（自动生成合规 W3C trace_id）→ 导出头
        $traceId = $ctx->startTrace(); // 不传参，由 kode/context 生成合规 32 位十六进制 trace_id
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
        $info = $ctx->getTraceInfo();
        self::assertSame($traceId, $info['trace_id']);
        $headers = $ctx->toHeaders();
        // 合规 trace_id 下应导出 W3C traceparent 头；私有头 X-Context-Trace-Id 始终存在
        self::assertArrayHasKey('traceparent', $headers);
        self::assertArrayHasKey('X-Context-Trace-Id', $headers);

        // 全局 ctx() 辅助函数（须先 boot，setUp 已重置单例）
        self::assertInstanceOf(\Kode\Core\Context::class, ctx());
        self::assertSame(42, ctx()->get('tenant_id'));

        $ctx->reset();
        self::assertFalse($ctx->has('tenant_id'));
    }

    public function testConsoleRuntimeModeAvailable(): void
    {
        // kode/console 已安装，enable('console') 应成功（不再抛异常）
        $app = App::boot([
            'config'  => ['app' => ['env' => 'testing']],
            'runtime' => ['console'],
        ]);
        self::assertTrue($app->runtime->has('console'));

        // 内置 6 种 + console 共 7 种模式均应在 supported() 中可查
        self::assertTrue($app->runtime->supported('console'));
    }

    public function testDiAttributeInjection(): void
    {
        // kode/di 2.x 支持 #[Inject] 属性注入：make 时自动填充标记属性
        $container = new Container();
        $container->singleton(Logger::class, fn() => new Logger());
        $svc = $container->make(AttributedService::class);

        self::assertInstanceOf(Logger::class, $svc->resolvedLogger());
    }

    public function testFacadeAutoContextSafeUnderCoroutine(): void
    {
        // 协程运行时（fiber）下，App 应自动为门面开启上下文安全模式
        $app = App::boot([
            'config'  => ['app' => ['name' => 'CtxSafe']],
            'runtime' => ['fiber'],
        ]);
        $app->container->singleton(DemoApi::class, fn() => new DemoApi('v1'));

        // 全局开关已被自动打开，且首次解析的门面已进入上下文安全模式
        self::assertTrue(\Kode\Core\Facade::isAutoContextSafe());
        DemoApiFacade::get();
        self::assertTrue(DemoApiFacade::isContextSafeMode());

        // 上下文安全模式下仍按容器实例正确解析（CLI 下单执行单元等价）
        self::assertSame($app->make(DemoApi::class), DemoApiFacade::getInstance());

        // 复位，避免污染其它测试
        \Kode\Core\Facade::resetAll();
        self::assertFalse(\Kode\Core\Facade::isAutoContextSafe());
    }
}

// ---------- 测试用辅助类（与应用代码同文件，避免额外加载） ----------

final class Logger
{
    public function info(string $m): void {}
}

final class Greeter
{
    public function __construct(public Logger $logger, public string $name)
    {
    }
    public function hello(): string
    {
        return "Hello, {$this->name}!";
    }
}

final class NeedsLogger
{
    public function __construct(public Logger $logger)
    {
    }
}

final class DemoProvider extends ServiceProvider
{
    public function register(): void
    {
        $name = $this->container->make(Config::class)->get('app.name', 'Kode');
        $this->container->singleton(Greeter::class, fn() => new Greeter($this->container->make(Logger::class), $name));
    }
}

// 静态门面演示：解析 DemoApi 服务
final class DemoApi
{
    public function __construct(public string $version)
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

// DI 属性注入演示：#[Inject] 标记的属性在 make 时自动填充
final class AttributedService
{
    #[Inject]
    private Logger $logger;

    public function resolvedLogger(): Logger
    {
        return $this->logger;
    }
}

