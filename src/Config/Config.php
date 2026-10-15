<?php
declare(strict_types=1);

/**
 * 配置仓库。
 *
 * 职责：在内存中保存一份配置树，支持：
 *   - 点号路径读取：  get('app.name')
 *   - 点号路径写入：  set('app.debug', true)
 *   - 存在判断：      has('app.name')
 *   - 批量合并：      merge($array)
 *
 * 这是“加载配置”阶段的核心数据结构，被注入到容器中供各服务读取。
 */
namespace Kode\Core\Config;

final class Config
{
    /** @var array<string, mixed> 配置树 */
    private array $items = [];

    /**
     * 写入配置（支持点号路径写入嵌套结构）。
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        /** @var array<string, mixed> $cursor */
        $cursor = &$this->items;
        foreach ($segments as $seg) {
            if (!is_array($cursor)) {
                $cursor = [];
            }
            $cursor = &$cursor[$seg];
        }
        $cursor = $value;
        unset($cursor);
    }

    /**
     * 按点号路径读取配置，支持默认值。
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $cursor = $this->items;
        foreach ($segments as $seg) {
            if (!is_array($cursor) || !array_key_exists($seg, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$seg];
        }
        return $cursor;
    }

    /**
     * 判断配置键是否存在（含值为 null 的情况）。
     */
    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $cursor = $this->items;
        foreach ($segments as $seg) {
            if (!is_array($cursor) || !array_key_exists($seg, $cursor)) {
                return false;
            }
            $cursor = $cursor[$seg];
        }
        return true;
    }

    /** 返回完整配置树。 */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * 合并一批配置（用于 Loader 加载的结果或 boot() 时传入的覆盖项）。
     *
     * 同名键两侧都是数组时：均为「列表」→ 后者整体替换；否则 → 递归合并。
     * 其余情况（标量 / 一侧数组一侧标量）→ 后者直接覆盖前者。
     */
    public function merge(array $items): void
    {
        $this->items = $this->mergeRecursive($this->items, $items);
    }

    private function mergeRecursive(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                // 列表按下标递归会让旧元素残留（覆盖 ['x'] 得到 ['x','b','c']，
                // 覆盖 [] 更是清不掉），因此列表语义恒为整体替换；关联数组仍递归合并。
                $a[$k] = (array_is_list($v) && array_is_list($a[$k]))
                    ? $v
                    : $this->mergeRecursive($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }
}
