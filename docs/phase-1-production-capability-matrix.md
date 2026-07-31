# Phase 1 生产插件能力矩阵

## 依据与状态定义

依据：

- `production-reference/swq-w3tc-polylang-purge.php`
- `docs/current-production-plugin-analysis.md`
- `docs/post-21018-runtime-cache-investigation.md`
- `docs/phase-1-post-save-runtime-validation.md`

状态只使用：

- **已确认**：当前生产 MU Plugin 中存在明确可执行代码，或已有对应运行时证据。
- **未发现**：当前生产 MU Plugin 没有明确实现；不表示对象一定发生故障。
- **尚未验证**：代码意图存在，但实际缓存后端、Host 传播或真实故障证据尚未验证。

## 矩阵

| 对象 / 场景 | 当前生产插件是否处理 | Hook | 缓存清理机制 | 是否考虑 Polylang | 是否考虑多 Host | 是否已有真实 Origin 故障证据 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| post | 是，仅内置 `post` | `w3tc_flush_post` | 语言首页 URL purge；flush `posts` group | 是，读取 post language | 共享 group version跨 Host失效 | 21022真实 admin Update已验证两个补偿 | 已确认 |
| post permalink | 无专门追加逻辑 | 无专门 hook | 依赖 W3TC 自身 post purge | 否 | 否 | 未发现 | 未发现 |
| post query | 是 | `w3tc_flush_post` | `wp_cache_flush_group( 'posts' )`，同请求最多一次 | 不处理翻译查询关系 | 共享 group version不含 Host | 21022 Update使 version从 `803` 变为 `805` | 已确认 |
| homepage | 是 | `w3tc_flush_post` | `pll_home_url()` 后调用 `w3tc_flush_url()` | 是 | 通过 Polylang动态 URL，未写 Host分支 | 21022 Update后、Origin GET前 en缓存文件不存在；随后重新生成 | 已确认 |
| term | 否 | 未发现 | 未发现 | 否 | 否 | 未发现 | 未发现 |
| category | 否 | 未发现 | 未发现 | 否 | 否 | 未发现 | 未发现 |
| tag | 否 | 未发现 | 未发现 | 否 | 否 | 未发现 | 未发现 |
| taxonomy archive | 否 | 未发现 | 未发现 | 否 | 否 | 未发现 | 未发现 |
| menu | 否 | 未发现 | 未发现 | 否 | 否 | 未发现 | 未发现 |
| option | 是，但只在 W3TC Object Cache flush 后 | `w3tc_flush_after_objectcache` | `wp_cache_flush_group( 'options' )` | 否 | 注释意图为跨 Host group version | 注释提及 WPCode option，但无保存的运行时对照 | 尚未验证 |
| `wp_template` | 否；被 `post_type === 'post'` 排除 | 未发现 | 未发现 | 否 | 否 | 本项目现有文档无足够分层证据 | 未发现 |
| `wp_template_part` | 否；被 `post_type === 'post'` 排除 | 未发现 | 未发现 | 否 | 否 | 未发现 | 未发现 |
| Global Styles | 否 | 未发现 | 未发现 | 否 | 否 | 未发现 | 未发现 |
| Theme JSON | 否 | 未发现 | 未发现 | 否 | 否 | 未发现 | 未发现 |
| Polylang translation relationship | 仅读取当前 post 语言 | `w3tc_flush_post` | 不遍历翻译对象或关联 URL | 部分 | 未专门处理 | 21018/21022 关系当前正常 | 尚未验证 |
| www Host | 无独立分支 | 无 Host 专用 hook | 语言首页 URL与 group flush 间接覆盖 | 部分 | 无显式 Host 判断 | 21018 当前 Origin 正常 | 尚未验证 |
| en Host | 无独立分支 | 无 Host 专用 hook | 语言首页 URL与 group flush间接覆盖 | 部分 | 无显式 Host判断 | 21022真实 Update已验证 en首页 purge与共享 version推进 | 已确认 |
| admin Host | 无独立分支 | 无 Host 专用 hook | group flush通过共享 version跨 Host传播 | 否 | 无显式 Host判断 | admin真实 Update使 version从 `803` 变为 `805` | 已确认 |

## 最重要的能力缺口

从代码覆盖面看，最重要的缺口是：插件只对内置 `post` 和 `posts` / `options` 两个 Object Cache group 有明确逻辑，没有 term、menu、Block Theme 对象、Global Styles、Theme JSON 或翻译关系遍历。

但从修复优先级看，这些目前都只是能力矩阵缺口。本项目现有资料不足以证明其中任何一项正在造成 Phase 1 Origin 故障，因此不能仅为补齐矩阵而直接实现。

## 已确认触发入口

W3TC 2.10.3 的 Page Cache 模块挂接 `save_post`。满足 `is_flushable_post()` 且 `w3tc_preflush_post` 未阻止后，会派发：

```php
do_action( 'w3tc_flush_post', $post_id, $force, $extras );
```

所以当前 MU Plugin 的 `w3tc_flush_post` 回调覆盖普通发布/更新路径，不应再描述为“可能只在手工 purge 时触发”。21022的真实 admin Update现已确认语言首页 purge和 `posts` group version推进均实际发生。该证据只覆盖本次英文内置 `post` 更新场景。
