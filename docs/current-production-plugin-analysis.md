# 当前生产 MU Plugin 静态分析

## 分析范围与方法

- 分析对象：`production-reference/swq-w3tc-polylang-purge.php`
- 原始插件标注版本：`1.3.0`
- 本文仅依据该文件进行静态分析。
- 未执行 WordPress、W3 Total Cache、Redis、CDN 或数据库操作。
- 本文不根据已经观察到的缓存故障反推原因。注释中描述的运行环境和行为会与代码能够直接证明的行为分开记录。

状态说明：

- **已从代码确认**：可由本文件的可执行代码直接确认。
- **代码中未发现**：本文件没有对应的 hook、API 调用或处理逻辑；不代表整个 WordPress 环境不存在该能力。
- **需要运行时验证**：取决于插件版本、配置、实际 hook 调用、缓存后端或请求 Host，不能仅凭本文件确定。

## 总览

该插件注册了 3 个 action，没有注册 filter：

| Hook | 回调 | 优先级 | 参数数 | 代码确认的作用 |
| --- | --- | ---: | ---: | --- |
| `w3tc_flush_post` | `swq_w3tc_polylang_queue_language_home` | 1200 | 3 | 对普通 `post` 查询其 Polylang 语言首页，并调用 `w3tc_flush_url()` |
| `w3tc_flush_post` | `swq_w3tc_sync_posts_object_cache_group` | 1250 | 3 | 对普通 `post` 调用 `wp_cache_flush_group( 'posts' )`，每个 PHP 请求最多一次 |
| `w3tc_flush_after_objectcache` | `swq_w3tc_sync_options_group_after_objectcache_flush` | 10 | 0 | 在该 action 触发后调用 `wp_cache_flush_group( 'options' )` |

所有清理逻辑都由 W3TC action 间接触发；本文件没有自行监听 `save_post`、term、option、menu 或主题文件更新事件。

## 1. 缓存清理入口与触发条件

### 已从代码确认

1. `w3tc_flush_post`
   - 同时进入语言首页 Page Cache 清理逻辑和 `posts` Object Cache group 清理逻辑。
   - 两个回调都会把 `$post_id` 转为整数，要求其大于 0。
   - 两个回调都会执行 `get_post( $post_id )`，并且仅当 `post_type` 严格等于 `post` 时继续。
   - `$force` 和 `$extras` 虽被接收，但没有参与任何条件判断。

2. `w3tc_flush_after_objectcache`
   - 进入 `options` Object Cache group 清理逻辑。
   - 代码不检查触发原因或当前 Host。

3. 两个 group flush 都要求：
   - `wp_cache_flush_group()` 存在；
   - `wp_cache_supports()` 存在；
   - `wp_cache_supports( 'flush_group' )` 返回真。

### 需要运行时验证

- 哪些 WordPress 管理操作或前台操作会让当前生产版本的 W3TC 触发 `w3tc_flush_post`。
- W3TC 是否会针对一次内容变更多次触发 `w3tc_flush_post`，以及传入哪些 `$force` / `$extras` 值。
- `w3tc_flush_after_objectcache` 在当前 W3TC 版本和配置中的全部触发路径。源码注释声称它会在 “Purge Module: Object Cache” 或 “Purge All Caches” 后触发，但本文件本身不能证明 W3TC 内部的 action 派发条件。
- 回调优先级 1200 是否确实处于 W3TC 原生 post flush 回调之后、但仍早于其延迟 Page Cache 清理。该顺序来自源码注释，需要结合当前 W3TC 版本验证。

## 2. Page Cache

### 已从代码确认

- 插件直接调用 W3TC 的 `w3tc_flush_url()`。
- 对 `post` 类型内容，插件通过：
  - `pll_get_post_language( $post_id, 'slug' )` 获取语言 slug；
  - `pll_home_url( $language )` 获取该语言首页；
  - `trailingslashit()` 规范化末尾斜杠；
  - `w3tc_flush_url()` 请求清理该语言首页 URL。
- 调用附带 `swq_source`、`post_id` 和 `language` 元数据。
- 若 W3TC 或必要的 Polylang 函数不存在，或无法取得有效语言及首页 URL，则不执行该 URL flush。
- 本文件自身没有调用 Page Cache 全站 flush。

### 代码中未发现

- 没有显式清理文章固定链接、文章归档、分类/标签归档、作者归档、日期归档、feed、搜索结果、分页页面或翻译文章 URL。
- 没有直接调用 CDN purge API。
- 没有针对 EdgeOne 或 Cloudflare 的代码。

### 需要运行时验证

- `w3tc_flush_url()` 在当前 W3TC 配置下具体删除或排队删除哪些 Page Cache 项。
- W3TC 自身在 `w3tc_flush_post` 流程中已经清理了哪些相关 URL；本插件只是追加语言首页，不能仅从本文件推导完整 Page Cache 覆盖范围。
- W3TC 的 Page Cache 清理能否进一步触发 EdgeOne / Cloudflare CDN 清理，以及不同 Host 的实际结果。

## 3. Object Cache

### 已从代码确认

- 插件使用 WordPress Object Cache API 的 group flush 能力：
  - `wp_cache_flush_group( 'posts' )`
  - `wp_cache_flush_group( 'options' )`
- `posts` group flush：
  - 只在 `w3tc_flush_post` 针对普通 `post` 时执行；
  - 用函数内静态变量 `$flushed` 保证同一 PHP 请求中最多执行一次。
- `options` group flush：
  - 在 `w3tc_flush_after_objectcache` action 每次触发时执行；
  - 没有同请求去重逻辑。
- 代码没有调用 `wp_cache_flush()`，也没有直接执行 Redis 命令或清空 Redis 数据库。

### 代码中未发现

- 没有清理 `terms`、`term_meta`、`comment`、`users`、`user_meta`、`site-options`、`transient` 等其他 Object Cache group 的显式逻辑。
- 没有按单个 cache key 精确删除对象。
- 没有 Redis 客户端调用、Redis key 拼接或 Redis 数据库级 flush。

### 需要运行时验证

- 当前 Object Cache drop-in 是否支持 `flush_group`，以及 `wp_cache_supports( 'flush_group' )` 是否返回真。
- 当前 W3TC Redis 实现中 group flush 的实际语义、group-version key 是否跨 Host 共享，以及它最终失效哪些 key。源码注释描述了这些行为，但本文件只调用 WordPress API，不能独立证明后端实现。
- `posts` 和 `options` group flush 是否足以覆盖真实故障，不能仅凭使用 Redis/W3TC 作出判断。

## 4. Host 与域名

### 已从代码确认

- 可执行代码没有读取或判断 `HTTP_HOST`，也没有 www/en/admin Host 分支。
- 可执行代码没有写死任何域名。
- 语言首页 URL 由 `pll_home_url()` 动态取得。
- `admin.shuijingwanwq.com`、`www`、`en` 以及 Host 分隔 key 的说明只出现在源码注释中。
- `shuijingwanwq.com` 字符串只出现在注释里的 `admin.shuijingwanwq.com`，不参与运行时逻辑。

### 需要运行时验证

- `pll_home_url()` 在三个 Host 发起的不同请求中是否稳定返回期望语言域名。
- 注释所称 W3TC 单项 Object Cache key 包含当前 Host、而 group-version key 不包含 Host 的行为，需对当前生产 W3TC/Object Cache 实现做运行时或依赖源码验证。
- www、en、admin 三个 Host 的 Page Cache / Object Cache 实际失效传播范围。

## 5. Polylang 翻译关系

### 已从代码确认

- 插件使用 Polylang 查询当前文章的语言，并获取该语言首页。

### 代码中未发现

- 没有调用翻译关系 API，例如获取当前文章的其他语言版本。
- 没有遍历或清理翻译文章、翻译 term 或各语言关联对象。
- 没有处理语言新增、删除、设置变化等 Polylang 事件。

### 需要运行时验证

- W3TC 或 Polylang 的其他代码是否已经处理翻译对象及其 URL，不在本文件的静态分析范围内。

## 6. WordPress 对象覆盖情况

| 对象/场景 | 状态 | 结论 |
| --- | --- | --- |
| `post` | 已从代码确认 | 仅严格匹配 `post_type === 'post'`；追加语言首页 URL purge，并 flush `posts` Object Cache group |
| 其他 post type | 代码中未发现 | 条件明确排除所有非 `post` 类型 |
| term | 代码中未发现 | 无 term hook、term API 或 term cache group 清理 |
| option | 已从代码确认 | 在 `w3tc_flush_after_objectcache` 后 flush 整个 `options` group；不监听单个 option 变更 |
| menu | 代码中未发现 | 无 menu hook 或 menu 专用清理 |
| `wp_template` | 代码中未发现 | 作为非 `post` 类型会被两个 `w3tc_flush_post` 回调明确排除 |
| `wp_template_part` | 代码中未发现 | 作为非 `post` 类型会被两个 `w3tc_flush_post` 回调明确排除 |
| Global Styles / Theme JSON | 代码中未发现 | 无 `wp_global_styles`、Theme JSON 或相关 hook/API |
| Polylang 翻译关系 | 部分确认 | 只获取当前 post 的语言及语言首页；不遍历翻译关系 |

## 7. 全站 flush 与开销

### 已从代码确认

- 本文件没有调用 W3TC 的全站 flush API。
- 本文件没有调用 WordPress 全 Object Cache flush。
- `posts` 和 `options` 都是整个 cache group 的失效，范围大于单 key 删除，但小于全 Object Cache flush。
- `posts` group flush 通过静态变量限制为同一请求最多一次。
- 每次符合条件的 `w3tc_flush_post` 回调会分别执行 `get_post()`；两个回调没有共享其查询结果。
- Page Cache 分支还会调用两个 Polylang 函数和一次 W3TC URL flush。

### 潜在高开销点（需要运行时验证）

- 整组失效会使该 group 中的缓存集中重建；实际成本取决于流量、key 数量、后端实现和失效频率。
- 高频触发 `w3tc_flush_after_objectcache` 时，`options` group 可能反复失效，因为该回调没有本请求去重。
- 两次 `get_post()` 通常可能被 WordPress 对象缓存吸收，但在特定缓存状态下的实际查询成本需要测量。
- 不能仅由静态代码断言这些逻辑已经构成生产性能问题。

## 8. 明显属于当前网站的实现

以下内容不适合未经抽象、配置化和验证就直接作为公共方案发布：

- `swq_` 函数名和元数据命名空间。
- 注释中对 `admin`、`www`、`en` 三个 Host 的固定环境假设。
- 注释中对 W3TC Redis key 与 group-version key 跨 Host 行为的特定假设。
- 只处理 WordPress 内置 `post` 类型的业务选择。
- 每次 post purge 都清理该语言首页的站点业务策略。
- Object Cache 只补充 `posts` 和 `options` 两个 group 的选择。
- 注释中特指 WPCode 的 `wpcode_snippets` option。
- 对 W3TC hook 优先级及内部延迟清理时序的版本相关假设。

虽然生产域名没有写入可执行代码，但上述环境和业务假设仍需要在公共版本设计前拆分为可验证、可配置的行为。

## 9. 当前结论边界

### 已确认覆盖

- 普通 `post` 对应语言首页的 W3TC URL purge 请求。
- 普通 `post` 触发时的 `posts` Object Cache group flush。
- W3TC object-cache flush 后的 `options` Object Cache group flush。

### 明显未覆盖或代码中未发现

- 非 `post` 的 post type，包括 `wp_template` 与 `wp_template_part`。
- term、menu、Global Styles / Theme JSON 的专门失效。
- Polylang 翻译对象遍历与关联 URL 清理。
- CDN 的显式清理。
- 文章之外各类归档、feed、搜索、分页等 URL 的显式追加清理。

### 尚不能确认

- W3TC 自身已经提供的完整清理范围。
- group flush 在当前 Redis/W3TC 实现中的跨 Host 真实效果。
- Page Cache 清理向两个 CDN 的传播效果。
- 已观察缓存故障的具体根因，以及它属于 Page Cache、Object Cache、CDN、应用逻辑还是多个层次的组合。
