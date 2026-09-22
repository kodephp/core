<?php

declare(strict_types=1);

namespace Kode\Core\Tests;

use Kode\Core\App;
use Kode\Core\Config\Config;
use Kode\Core\Context;
use Kode\Core\Facade;
use Kode\Core\Runtime\Runtime;
use Kode\DI\Container;
use Kode\Facade\FacadeProxy;
use PHPUnit\Framework\TestCase;

/**
 * v1.5.0 审计回归：配置列表合并语义、门面协程隔离时序、运行时环境真值、
 * terminating 回调互不拖累、启动前调用全局助手。
 */
final class CoreHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        $this->forgetApp();
        Facade::resetAll();
    }

    protected function tearDown(): void
    {
        Facade::resetAll();
    }

    /** App::boot() 是单例：再次启动前必须清掉实例，否则 boot() 直接复用旧应用。 */
    private function forgetApp(): void
    {
        $ref = new \ReflectionClass(App::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ---------- Config：列表恒为整体替换 ----------

    public function testListOverrideReplacesWholesale(): void
    {
        $config = new Config();
        $config->merge(['middleware' => ['a', 'b', 'c']]);
        $config->merge(['middleware' => ['x']]);

        // 旧语义按下标拼接会得到 ['x','b','c']：覆盖后残留旧元素
        self::assertSame(['x'], $config->get('middleware'));
    }

    public function testEmptyListOverrideClearsTheList(): void
    {
        $config = new Config();
        $config->merge(['middleware' => ['a', 'b', 'c']]);
        $config->merge(['middleware' => []]);

        self::assertSame([], $config->get('middleware'));
    }

    public function testAssocArraysStillMergeDeeply(): void
    {
        $config = new Config();
        $config->merge(['db' => ['host' => 'localhost', 'options' => ['timeout' => 5]]]);
        $config->merge(['db' => ['port' => 5432, 'options' => ['ssl' => true]]]);

        self::assertEquals(
            ['host' => 'localhost', 'port' => 5432, 'options' => ['timeout' => 5, 'ssl' => true]],
            $config->get('db')
        );
    }

    public function testBootTimeConfigOverrideReplacesListFromDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/kode-cfg-' . uniqid('', true);
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/pipeline.php', '<?php return ["middleware" => ["a", "b", "c"]];');

        try {
            // 目录配置提供列表，boot() 内联覆盖（「最高优先级」）只给一项：
            // 旧语义得到 ['x','b','c']，残留的 b/c 仍会进管道
            $app = App::boot([
                'config_path' => $dir,
                'config'      => ['pipeline' => ['middleware' => ['x']]],
            ]);

            self::assertSame(['x'], $app->config('pipeline.middleware'));
        } finally {
            unlink($dir . '/pipeline.php');
            rmdir($dir);
        }
    }

    // ---------- Facade：协程隔离不再受解析先后影响 ----------

    public function testFacadeResolvedBeforeAutoContextSafeIsRepaired(): void
    {
        $container = new Container();
        $container->singleton(ProbeService::class, fn() => new ProbeService());
        Facade::setContainer($container);

        // 模拟服务提供者 register() 期就用到门面：此时自动隔离开关尚未打开
        ProbeFacade::getInstance();
        self::assertFalse(ProbeFacade::isContextSafeMode());

        // 启动序列检测到协程运行时后才打开开关 —— 已解析过的门面必须被追溯补开，
        // 否则常驻进程里每个协程共享同一份门面实例缓存（跨协程污染）
        Facade::setAutoContextSafe(true);
        self::assertTrue(ProbeFacade::isContextSafeMode());

        // 开关打开后才首次解析的门面同样隔离
        LateProbeFacade::getInstance();
        self::assertTrue(LateProbeFacade::isContextSafeMode());
    }

    public function testAlreadyBoundFacadeIsRepairedOnNextResolve(): void
    {
        $container = new Container();
        $container->singleton(ProbeService::class, fn() => new ProbeService());
        Facade::setContainer($container);

        // 手动绑定（不经自动登记路径）：追溯补开只覆盖「解析过的」门面，
        // 因此隔离必须由每次 getInstance 自查补上，而不是只在首次绑定时判断
        ProbeFacade::bind(ProbeService::class);
        self::assertFalse(ProbeFacade::isContextSafeMode());

        Facade::setAutoContextSafe(true);
        ProbeFacade::getInstance();
        self::assertTrue(ProbeFacade::isContextSafeMode());
    }

    public function testResetAllClearsFacadeBindings(): void
    {
        $container = new Container();
        $container->singleton(ProbeService::class, fn() => new ProbeService());
        Facade::setContainer($container);

        ProbeFacade::getInstance();
        self::assertTrue(FacadeProxy::isBound(ProbeFacade::class));

        Facade::resetAll();
        self::assertFalse(FacadeProxy::isBound(ProbeFacade::class));
        self::assertFalse(Facade::isAutoContextSafe());

        // 复位后可重新解析
        self::assertInstanceOf(ProbeService::class, ProbeFacade::getInstance());
        self::assertTrue(FacadeProxy::isBound(ProbeFacade::class));
    }

    // ---------- Runtime：环境真值 vs 启用记录 ----------

    public function testBootDoesNotOverrideAlreadyActiveEnvironment(): void
    {
        $runtime = new Runtime();
        $runtime->enable('fiber');
        $runtime->boot(); // 已有生效环境时不得回落到 cli

        self::assertSame('fiber', $runtime->environment());
        // kode/runtime 的环境名是大写标签，二者必须指向同一个环境
        self::assertSame('FIBER', $runtime->getName());

        $runtime->enable('cli'); // 还原全局静态环境，避免污染其它用例
    }

    public function testEnvironmentTracksTheLastEnabledMode(): void
    {
        $runtime = new Runtime();
        self::assertNull($runtime->environment());

        $runtime->boot();
        self::assertSame('cli', $runtime->environment());

        $runtime->enable('fiber');
        self::assertSame('fiber', $runtime->environment());
        // has() 仍是「启用过」的单调记录，与「当前生效」区分开
        self::assertTrue($runtime->has('cli'));
        self::assertTrue($runtime->has('fiber'));

        $runtime->enable('cli');
        self::assertSame('cli', $runtime->environment());
        self::assertSame('CLI', $runtime->getName());
    }

    // ---------- Context::raw() ----------

    public function testContextRawReturnsUnderlyingClassName(): void
    {
        self::assertSame(
            \Kode\Context\Context::class,
            (new Context())->raw()
        );
    }

    // ---------- 协程隔离开关按「当前生效环境」判定 ----------

    public function testAutoContextSafeFollowsActiveEnvironmentNotEnableHistory(): void
    {
        // fiber 被 cli 覆盖后，进程实际跑在 cli：不应打开协程隔离
        App::boot(['runtime' => ['fiber', 'cli']]);
        self::assertFalse(Facade::isAutoContextSafe());

        Facade::resetAll();
        $this->forgetApp();
        App::boot(['runtime' => ['fiber']]);
        self::assertTrue(Facade::isAutoContextSafe());
    }

    // ---------- terminating 回调互不拖累 ----------

    public function testTerminatingCallbackFailureDoesNotSkipTheRest(): void
    {
        $app = App::boot([]);
        $log = [];

        $app->terminating(static function () use (&$log): void {
            $log[] = 'first';
        });
        $app->terminating(static function (): void {
            throw new \RuntimeException('boom');
        });
        $app->terminating(static function () use (&$log): void {
            $log[] = 'third';
        });

        $logFile = tempnam(sys_get_temp_dir(), 'kode-term-');
        self::assertIsString($logFile);
        $previous = ini_set('error_log', $logFile);

        try {
            $app->callTerminatingCallbacks();
        } finally {
            if (is_string($previous)) {
                ini_set('error_log', $previous);
            }
        }

        $captured = (string) file_get_contents($logFile);
        unlink($logFile);

        self::assertSame(['first', 'third'], $log);
        self::assertStringContainsString('boom', $captured);

        // 幂等：清空后重复调用不再执行任何回调
        $app->callTerminatingCallbacks();
        self::assertSame(['first', 'third'], $log);
    }

    // ---------- 全局助手：启动前给出可读错误 ----------

    public function testAppHelperResolvesNothingBeforeBootWithoutArgument(): void
    {
        self::assertNull(app());
    }

    public function testAppHelperThrowsReadableErrorBeforeBoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App 尚未启动');
        app(ProbeService::class);
    }

    public function testConfigHelperThrowsReadableErrorBeforeBoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App 尚未启动');
        config('app.name');
    }

    public function testConfigHelperNoArgThrowsReadableErrorBeforeBoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App 尚未启动');
        config();
    }
}

// ---------- 测试用辅助类 ----------

final class ProbeService
{
}

final class ProbeFacade extends Facade
{
    protected static function id(): string
    {
        return ProbeService::class;
    }
}

final class LateProbeFacade extends Facade
{
    protected static function id(): string
    {
        return ProbeService::class;
    }
}
