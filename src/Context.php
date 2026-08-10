<?php
declare(strict_types=1);

/**
 * Context —— 协程/分布式上下文的薄封装（桥接 kode/context）。
 *
 * kode/context 为 Fiber / Swoole / Swow / 多线程 / 多进程 / 同步 等运行环境
 * 提供“执行单元级”的上下文隔离与传递；本类在其之上做一层语义化封装，
 * 让应用侧以更直观的方式使用：
 *
 *   - 基础读写：set / get / has / delete / pull / push
 *   - 作用域控制：run / fork / transaction / with（自动回滚，避免污染）
 *   - 分布式链路追踪：startTrace / startSpan / toHeaders / fromHeaders / continueTrace
 *   - 跨节点序列化：toJson / fromJson / exportForDistributed
 *   - 运行时探测：runtime() / getRuntime() / isCoroutine()
 *
 * 所有调用最终转发到 Kode\Context\Context（静态实现，进程内同一份根上下文）；
 * 本类不持有状态，仅为“应用语境下的便捷入口”。
 *
 * 典型用法（在 App::boot 之后）：
 *   $app->context()->startTrace();                  // 开启一条链路
 *   $app->context()->set('tenant_id', 42);
 *   $headers = $app->context()->toHeaders();        // 透传到下游服务
 *   ctx()->fromHeaders($incomingHeaders);           // 在消费者侧恢复链路
 */
namespace Kode\Core;

use Kode\Context\Context as KodeContext;

final class Context
{
    /** 在当前上下文中写入键值。 */
    public function set(string $key, mixed $value): static
    {
        KodeContext::set($key, $value);
        return $this;
    }

    /** 读取上下文值，不存在返回 $default。 */
    public function get(string $key, mixed $default = null): mixed
    {
        return KodeContext::get($key, $default);
    }

    /** 是否存在某键。 */
    public function has(string $key): bool
    {
        return KodeContext::has($key);
    }

    /** 删除某键。 */
    public function delete(string $key): static
    {
        KodeContext::delete($key);
        return $this;
    }

    /** 取出并删除某键。 */
    public function pull(string $key, mixed $default = null): mixed
    {
        return KodeContext::pull($key, $default);
    }

    /** 向数组型键值追加元素。 */
    public function push(string $key, mixed ...$values): static
    {
        KodeContext::push($key, ...$values);
        return $this;
    }

    /** 仅当键不存在时写入（返回是否发生写入）。 */
    public function add(string $key, mixed $value): bool
    {
        return KodeContext::add($key, $value);
    }

    /**
     * 在全新空白作用域中执行回调，结束后自动回滚。
     * @template T
     * @param callable(): T $callable
     * @return T
     */
    public function run(callable $callable): mixed
    {
        return KodeContext::run($callable);
    }

    /**
     * 继承当前上下文的新作用域中执行回调，结束后自动回滚。
     * @template T
     * @param callable(): T $callable
     * @return T
     */
    public function fork(callable $callable): mixed
    {
        return KodeContext::fork($callable);
    }

    /**
     * 在当前上下文的事务作用域中执行回调，离开时恢复进入前快照（异常也恢复）。
     * @template T
     * @param callable(): T $callable
     * @return T
     */
    public function transaction(callable $callable): mixed
    {
        return KodeContext::transaction($callable);
    }

    /** 开启一条新的分布式链路，返回 trace_id。 */
    public function startTrace(?string $traceId = null, ?string $nodeId = null): string
    {
        return KodeContext::startTrace($traceId, $nodeId);
    }

    /** 创建子 Span，返回新的 span_id。 */
    public function startSpan(): string
    {
        return KodeContext::startSpan();
    }

    /** 读取当前链路信息（trace_id / span_id / parent_span_id / node_id）。 */
    public function getTraceInfo(): array
    {
        return KodeContext::getTraceInfo();
    }

    /** 是否处于采样状态。 */
    public function isSampled(): bool
    {
        return KodeContext::isSampled();
    }

    /** 导出用于跨节点传递的 HTTP 头（含 W3C traceparent/tracestate/baggage）。 */
    public function toHeaders(string $prefix = 'X-Context-'): array
    {
        return KodeContext::toHeaders($prefix);
    }

    /** 从 HTTP 头恢复上下文（优先 W3C 标准头，回退私有 X-Context-* 头）。 */
    public function fromHeaders(array $headers, string $prefix = 'X-Context-'): void
    {
        KodeContext::fromHeaders($headers, $prefix);
    }

    /** 从任意来源头继续链路（无 trace 时自动开启新链路）。 */
    public function continueTrace(array $headers, string $prefix = 'X-Context-'): void
    {
        KodeContext::continueTrace($headers, $prefix);
    }

    /** 导出为 JSON 字符串（可指定仅导出部分键）。 */
    public function toJson(array $onlyKeys = []): string
    {
        return KodeContext::toJson($onlyKeys);
    }

    /** 从 JSON 恢复上下文（$merge=true 合并，否则整体替换）。 */
    public function fromJson(string $json, bool $merge = false): array
    {
        return KodeContext::fromJson($json, $merge);
    }

    /** 导出用于跨节点传递的上下文快照。 */
    public function exportForDistributed(): array
    {
        return KodeContext::exportForDistributed();
    }

    /** 当前运行时类型（kode/context 的 Runtime 枚举）。 */
    public function runtime(): \Kode\Context\Runtime
    {
        return KodeContext::runtime();
    }

    /** 当前运行时类型字符串（fiber/swoole/swow/thread/process/sync）。 */
    public function getRuntime(): string
    {
        return KodeContext::getRuntime();
    }

    /** 是否处于协程/纤程环境。 */
    public function isCoroutine(): bool
    {
        return KodeContext::isCoroutine();
    }

    /** 清空当前上下文全部数据（常用于测试隔离）。 */
    public function reset(): void
    {
        KodeContext::reset();
    }

    /** 返回底层原始静态上下文类，便于调用本封装未覆盖的高级方法。 */
    public function raw(): KodeContext
    {
        return KodeContext::class;
    }
}
