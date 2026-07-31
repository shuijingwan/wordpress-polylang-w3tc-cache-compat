# 当前 MU Plugin 对已确认原生缺口的补偿

本文只比较当前生产参考版本
`production-reference/swq-w3tc-polylang-purge.php` 与两个已经确认的
W3TC 2.10.3 原生缺口。结论来自该 MU Plugin 的静态代码、当前生产
W3TC 2.10.3 的必要源码片段，以及此前保存的 Polylang URL 运行时结果；
本轮没有执行生产写入或缓存清理。

## A. English homepage Page Cache invalidation

| 项目 | 判断 |
| --- | --- |
| W3TC 原生行为 | `w3tc_flush_post` 的 Page Cache callback 在 priority 1100 计算原生目标。admin 保存英文文章时，文章 permalink 可由 Polylang生成 en URL，但首页仍来自默认 WordPress home，即 www。 |
| 原生缺口 | 英文首页 `https://en.shuijingwanwq.com/` 不在原生首页 purge 目标中。 |
| 现有 MU Plugin 行为 | 在 `w3tc_flush_post` priority 1200 接收 `$post_id`；读取该 ID 的 `WP_Post`，且只继续处理严格等于 `post` 的 post type；调用 `pll_get_post_language( $post_id, 'slug' )` 取得对象语言，再把该 slug 传给 `pll_home_url()`；最后把带尾斜杠的语言首页传给 `w3tc_flush_url()`。 |
| 实际补偿机制 | 语言判断绑定文章对象，而非当前后台语言。当前 Polylang 运行时结果为 zh → `https://www.shuijingwanwq.com/`、en → `https://en.shuijingwanwq.com/`；因此 21018 得到 www 首页，21022 得到 en 首页。W3TC 的 `w3tc_flush_url()` 经 `w3tc_flush_url` action 进入 Page Cache callback，把传入的完整 URL加入当前请求的 purge 队列。 |
| 影响范围 | 追加一个与文章语言对应的根首页 URL。W3TC按该完整 URL的 host/path计算并清理对应 Page Cache 项；这不是全站 Page Cache flush，也不追加另一语言首页、后台 URL、feed、分页或其他 archive。W3TC原生 post purge的其他目标不属于这段补偿逻辑。 |
| 是否依赖当前 Host | 否。代码不读取或修改 `HTTP_HOST`，也不使用 admin URL；目标 Host由 `pll_home_url(文章语言)` 返回的完整 URL决定。 |
| 是否需要多次执行 | 对一次英文文章的 `w3tc_flush_post`，一次 callback 即可把 en 首页加入队列。W3TC API还会在同一执行中对相同 URL去重；不需要分别在 admin、www、en 执行。 |
| 当前判断 | **已确认补偿** |

限定条件：若 post ID无效、对象不是内置 `post`、必要的 W3TC/Polylang
函数不可用，或 Polylang不能返回有效语言/首页，callback会安全退出。
这些保护条件不改变 21022 作为正常英文 `post` 时的静态结论。

## B. Cross-Host post Object Cache invalidation

| 项目 | 判断 |
| --- | --- |
| W3TC 原生行为 | WordPress保存文章时会清理 Core post对象并更新 `posts:last_changed`，但 W3TC Redis的物理 item key包含当前 `HTTP_HOST`。在 admin请求中，这些单 key操作只作用于 admin物理 namespace；W3TC原生 `w3tc_flush_post` 没有额外 flush `posts` group。 |
| 原生缺口 | www/en物理 namespace中的旧 post对象或 posts query相关对象不会因 admin namespace的 Core单 key失效而自然失效。 |
| 现有 MU Plugin 行为 | 在 `w3tc_flush_post` priority 1250 接收 `$post_id`；仅对有效的内置 `post`，并且仅在 Object Cache声明支持 `flush_group` 时，调用标准 WordPress API `wp_cache_flush_group( 'posts' )`。函数内 static标志使同一 PHP请求最多调用一次。 |
| 实际补偿机制 | 当前 W3TC object-cache drop-in把 `wp_cache_flush_group()` 转交给 `ObjectCache_WpObjectCache_Regular::flush_group()`，后者清空本请求的 runtime cache并调用 Redis engine的 `flush( 'posts' )`。Redis engine递增 `posts` group version。物理 item key格式含 Host，但 group-version key格式为 `w3tc_{instance_id}_{blog_id}_{module}_posts_key_version`，不含 Host。www/en/admin旧 item携带旧版本；随后任一 Host读取时，W3TC比较共享当前版本并把旧版本视为 cache miss，WordPress按正常路径从数据库取得数据并以新版本重新写入该 Host的缓存。旧物理 key可暂时留在 Redis至过期，但不再作为当前版本命中。 |
| 影响范围 | 使 `posts` group整体进入新一代，包括该 group中的 post对象、`posts:last_changed` 和 query相关缓存；范围大于精确单 key删除，但不清整个 Redis，也不清 options、terms、post_meta等其他 group。首次后续读取会产生该 group的缓存重建成本。 |
| 是否依赖当前 Host | 触发发生在 admin请求，但失效效果依靠不含 Host的共享 group version，不依赖当前 Host恰好等于 www或 en。 |
| 是否需要多次执行 | 一次成功的 `wp_cache_flush_group( 'posts' )` 足以提升共享版本，使 www/en/admin三个 Host的旧 `posts` items失效。无需针对三个 Host重复调用，也无需切换或伪造 `HTTP_HOST`。同一 PHP请求由 static标志去重；不同发布/更新请求可再次递增版本，这是预期的再次失效，不是全局只执行一次。 |
| 当前判断 | **已确认补偿** |

该操作的代价是下一次访问需要重建受影响的 `posts` group缓存，而不是只重建
当前文章。以文章发布/更新为触发边界、同请求最多一次，并且不触及其他 group
或整个 Redis，当前属于可接受的有限范围失效。重复提升版本在语义上安全，但
仍会造成新的失效和重建，因此不应描述为跨请求“零成本幂等”。

## 当前决策

A 和 B 均为 **已确认补偿**。当前建议：

- 保留现有生产 MU Plugin；
- 不再寻找替代插件；
- 不开发或重写新的插件；
- 21022的真实 admin Update已经完成运行时验证：en首页 Page Cache在
  Origin GET前不存在，随后重新生成；共享 `posts` group version从
  `803` 推进到 `805`；
- A、B因此均为“静态已确认补偿 + 运行时已确认补偿”。

插件中的 `options` group逻辑确实存在：W3TC派发
`w3tc_flush_after_objectcache` 后调用 `wp_cache_flush_group( 'options' )`。
它不属于本轮 A/B 范围，其生产必要性和对应真实故障仍为 **尚未确认**。
