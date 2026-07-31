# Phase 1：Origin Cache Consistency 范围

## 状态

- Phase 1：in progress
- Phase 2：deferred

Phase 1 只处理服务器内部缓存一致性。所有结论必须区分数据库、WordPress PHP、Persistent Object Cache、W3TC Page Cache 和 Origin HTTP，不使用公网 CDN 响应代替源站证据。

## Historical Baseline

迁移前已经是 Polylang 中英文双语站点，但两种语言共用一个 Host：

| 语言 | 旧 URL 模型 |
| --- | --- |
| zh | `https://www.shuijingwanwq.com/` |
| en | `https://www.shuijingwanwq.com/en/` |

历史记录是：旧架构下中文、英文前台缓存、W3TC Page Cache 和 W3TC Object Cache + Redis 均曾正常工作，没有已确认的 Polylang 双语缓存故障。

迁移后的 URL 模型：

| 用途 | 新 URL 模型 |
| --- | --- |
| zh 前台 | `https://www.shuijingwanwq.com/` |
| en 前台 | `https://en.shuijingwanwq.com/` |
| 后台操作入口 | `https://admin.shuijingwanwq.com/` |

即从“单 Host + 语言 path”变为“语言独立域名 + 独立后台 Host”。这是用于缩小 W3TC/WordPress/Polylang 缓存失效调查范围的重要历史基线，不证明新问题一定由 Host 导致。

## 包含范围

1. **WordPress runtime**：核心对象 API、查询、hook 与最终渲染。
2. **Polylang language/domain resolution**：语言、域名、permalink、canonical、hreflang 和语言上下文。
3. **W3TC Object Cache**：启用状态、engine、group/key 失效语义。
4. **Redis persistent objects**：Persistent Object Cache 中对象的 Host 隔离、共享和陈旧状态。
5. **W3TC Page Cache**：URL 映射、缓存文件、生成时间和 HTML 内容。
6. **Host isolation**：www、en、admin 的 cache namespace 与运行时上下文。
7. **post cache invalidation**：post 对象及内容变更后的失效。
8. **query cache invalidation**：`WP_Query` 和相关 `posts` group 查询缓存。
9. **term cache invalidation**：term 对象和 term meta。
10. **category cache invalidation**：分类对象、关系与归档输出。
11. **tag cache invalidation**：标签对象、关系与归档输出。
12. **menu cache invalidation**：导航菜单对象、关系与最终输出。
13. **option cache invalidation**：`get_option()`、`alloptions` 及相关 group。
14. **`wp_template`**：Block Theme 模板对象和渲染结果。
15. **`wp_template_part`**：模板部件对象和渲染结果。
16. **Global Styles**：`wp_global_styles` 对象与生成 CSS/HTML。
17. **Theme JSON**：Theme JSON 合并结果及相关缓存。
18. **Polylang translation relationships**：post/term 翻译关系及语言 URL。
19. **www / en / admin 多 Host 缓存行为**：相同数据库、不同 Host 下的 PHP 和缓存结果。
20. **Nginx / Origin HTTP 最终输出**：绕过 CDN 后的真实状态码、headers 和 HTML。

这些项目属于验证范围，不代表它们已经发生真实故障。

## 明确排除

Phase 1 不分析或实施：

- EdgeOne；
- Cloudflare；
- CDN API；
- CDN purge；
- CDN TTL；
- CDN credential、失败重试或异步任务；
- 浏览器缓存。

上述内容统一归入 Phase 2。

## 最终目标

Phase 1 的目标不是“把所有缓存都清掉”，而是在完全忽略 CDN 的情况下，使：

- `www.shuijingwanwq.com`
- `en.shuijingwanwq.com`

直接访问 Origin 时，在内容发生变化后稳定返回：

- 最新内容；
- 正确语言；
- 正确 Host；
- 正确 canonical；
- 正确 hreflang；
- 正确内部链接；
- 正确文章与翻译关系。

同时，WordPress PHP 层的：

- `get_post()`
- `get_term()`
- `get_option()`
- `WP_Query`
- Polylang API

不能因为 Persistent Object Cache 返回旧对象、跨语言串用、跨 Host 串用，或出现数据库已更新但 PHP 仍返回旧值。

## 正确性原则

- 优先精确失效与可证明的一致性，不以全站 flush 作为默认方案。
- “当前生产插件没有专门代码”只表示能力缺口，不表示对象已经发生故障。
- `<html lang>` 是最终 Origin 验证项；项目背景中若曾提及英文页输出 `zh-CN`，当前只能标记为尚未验证，不能作为已知故障。
- 21018 / 21022 的 Origin 已确认正确，公网 CDN 旧内容属于 Phase 2，不计入 Phase 1 故障。
- `admin.shuijingwanwq.com` 是后台操作入口，不默认把它设计成与 www/en 对等的第三个前台缓存空间；要验证的是后台变更能否正确失效实际前台 Host。
