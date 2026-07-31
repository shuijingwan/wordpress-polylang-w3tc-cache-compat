# W3TC 2.10.3 原生 `save_post` 缓存失效基线

## 1. 范围与结论

本文件只分析：

- WordPress 7.0.2；
- W3 Total Cache 2.10.3；
- Polylang 3.8.6；
- W3TC 官方 `save_post` 流程；
- `w3tc_flush_post` 扩展边界之前及 action 内 W3TC 官方优先级 1100 的行为。

不进入自定义 MU Plugin callback，不执行发布、缓存清理、Redis/数据库修改或环境模拟。

结论属于允许的类型 A：

**已从当前生产版本源码确认 W3TC/WordPress 原生机制存在明确的多 Host 失效缺口。**

最小缺口有两个相互独立的表现：

1. Page Cache：从 admin 保存英文 post 时，post permalink 能按对象语言生成 en URL，但首页和 blog feed 使用默认 www home；en 首页不进入 W3TC 官方队列。
2. Object Cache：WordPress Core 正常清理 post key 并更新 `posts:last_changed`，但 W3TC Redis item key 包含当前 `HTTP_HOST`；admin 请求只失效 admin namespace，W3TC 官方 post 流程没有额外跨 Host `posts` group flush。

本文只建立基线，不设计修复。

## 2. W3TC `save_post` callback

### 注册

文件：`wp-content/plugins/w3-total-cache/Util_AttachToActions.php`

类：`W3TC\Util_AttachToActions`

方法：`flush_posts_on_actions()`

```php
add_action(
    'save_post',
    array( $w3tc_o, 'on_post_change' ),
    0,
    2
);
```

`W3TC\PgCache_Plugin::run()` 在 Page Cache 模块运行时调用 `Util_AttachToActions::flush_posts_on_actions()`。

### callback

文件：`Util_AttachToActions.php`

方法：`W3TC\Util_AttachToActions::on_post_change( $post_id, $post )`

它：

1. 必要时 `get_post( $post_id )`；
2. 取得 W3TC config 和 `CacheFlush` component；
3. attachment 时可能处理 parent；
4. 调用 `Util_Environment::is_flushable_post( $post, 'posts', $config )`；
5. 通过后调用 `$cacheflush->flush_post( $post_id )`。

## 3. 完整调用链

```text
WordPress wp_insert_post()
  ├─ clean_post_cache( $post_id )
  ├─ get_post( $post_id )
  └─ do_action( 'save_post', $post_id, $post, $update )
       └─ W3TC\Util_AttachToActions::on_post_change()
            └─ W3TC\Util_Environment::is_flushable_post(
                   $post,
                   'posts',
                   $config
               )
                 └─ apply_filters(
                        'w3tc_flushable_post',
                        $flushable,
                        $post,
                        'posts'
                    )
            └─ W3TC\CacheFlush::flush_post()
                 └─ W3TC\CacheFlush_Locally::flush_post()
                      ├─ apply_filters(
                      │      'w3tc_preflush_post',
                      │      true,
                      │      $extras
                      │  )
                      └─ do_action(
                             'w3tc_flush_post',
                             $post_id,
                             $force,
                             $extras
                         )
                          ├─ priority 1100:
                          │    PgCache_Plugin::w3tc_flush_post()
                          │      └─ PgCache_Flush::flush_post()
                          │           └─ calculate + queue Page Cache URLs
                          ├─ extension boundary after official 1100
                          ├─ priority 2000: Varnish, if enabled
                          └─ priority 3000: official CDN integrations,
                               if enabled

shutdown / redirect:
  CacheFlush::execute_delayed_operations()
    └─ CacheFlush_Locally::_execute_delayed_operations_pgcache()
         └─ PgCache_Flush::flush_post_cleanup()
              └─ derive Page Cache keys from queued full URLs
              └─ delete cache keys
```

## 4. `is_flushable_post()` 条件

文件：`Util_Environment.php`

方法：`W3TC\Util_Environment::is_flushable_post()`

默认条件：

- 参数能解析为 post object；
- post type 不是 `revision` 或 `attachment`；
- status 为 `publish`；
- 若 `pgcache.reject.logged` 不启用，也可加入 `private`；
- 最终仍经过 `w3tc_flushable_post` filter。

因此普通已发布 post 的发布/更新属于官方 `save_post` 路径，不是只在手工 purge 时处理。

## 5. `w3tc_flush_post` 所在阶段

必须区分三个时点：

### 5.1 调用 `do_action()` 以前

W3TC 只完成：

- `is_flushable_post()` 判定；
- `w3tc_preflush_post` filter；
- 决定是否派发 action。

**W3TC 尚未计算 Page Cache URL，也尚未删除 Page Cache 文件。**

WordPress Core 已在 `save_post` 之前执行 `clean_post_cache()`，这是 Core Object Cache 失效，不是 W3TC Page Cache 工作。

### 5.2 action 内 priority 1100 以后

W3TC Page Cache 官方 callback：

```php
add_action(
    'w3tc_flush_post',
    array( $this, 'w3tc_flush_post' ),
    1100,
    2
);
```

调用 `PgCache_Flush::flush_post()`，计算并写入内存中的 `queued_urls`。此时 URL 已排队，但缓存文件通常尚未删除。

因此，以 priority > 1100 挂接的扩展 callback 能看到“官方已经完成 URL 计算/排队”的阶段。

### 5.3 shutdown / redirect

`flush_post_cleanup()` 才把每个完整 URL 交给 `_get_page_key()`：

- `wp_parse_url()` 取得 URL 自身的 host/path/query；
- cache key 明确包含解析出的 host；
- `_flush_url()` 最终删除对应 Page Cache key。

完整 purge URL 的 Host 直接决定删除哪个 Host 的缓存文件。

## 6. 当前生产 purge 配置

只读取得的相关配置：

| 配置 | 值 |
| --- | --- |
| `pgcache.purge.home` | `true` |
| `pgcache.purge.front_page` | `false` |
| `pgcache.purge.post` | `true` |
| `pgcache.purge.comments` | `false` |
| `pgcache.purge.author` | `false` |
| `pgcache.purge.terms` | `false` |
| daily/monthly/yearly archive | 全部 `false` |
| `pgcache.purge.feed.blog` | `true` |
| comments/author/terms feed | 全部 `false` |
| `pgcache.purge.feed.types` | `["rss2"]` |
| `pgcache.purge.pages` | `[]` |
| `pgcache.purge.postpages_limit` | `10` |
| `pgcache.rest` | 空 |
| `show_on_front` | `posts` |
| WordPress `home` | `https://www.shuijingwanwq.com` |
| WordPress `siteurl` option | `https://www.shuijingwanwq.com` |

所以 21018/21022 的当前实际官方队列类别只有：

- 首页及首页 pagination；
- post permalink 及 post 内部分页（如果内容包含 `<!--nextpage-->`）；
- blog RSS2 feed。

category、tag、其他 taxonomy、author/date archive、comment feed、term feed、自选页面和 REST 在当前配置下不会加入队列。

## 7. action 内官方支持的 Page Cache 目标

文件：`PgCache_Flush.php`

方法：`W3TC\PgCache_Flush::flush_post()`

| URL 类别 | 配置条件 | W3TC helper | 代码状态 |
| --- | --- | --- | --- |
| 首页 | `purge.home` + `show_on_front=posts`，或 `purge.front_page` | `get_frontpage_urls()` | 已处理 |
| posts page | `purge.home` + static front page | `get_postpage_urls()` | 已处理 |
| CPT archive | `purge.home` + CPT | `get_cpt_archive_urls()` | 已处理 |
| post permalink | `purge.post` 或 force | `get_post_urls()` | 已处理 |
| post 内分页 | 同 post permalink | `get_post_urls()` | 已处理 |
| comment pagination | `purge.comments` | `get_post_comments_urls()` | 已处理 |
| author archive + pagination | `purge.author` | `get_post_author_urls()` | 已处理 |
| category/tag/taxonomy archive + pagination | `purge.terms` | `get_post_terms_urls()` | 已处理 |
| daily archive + pagination | `purge.archive.daily` | `get_daily_archive_urls()` | 已处理 |
| monthly archive + pagination | `purge.archive.monthly` | `get_monthly_archive_urls()` | 已处理 |
| yearly archive + pagination | `purge.archive.yearly` | `get_yearly_archive_urls()` | 已处理 |
| blog/CPT feed | `purge.feed.blog` | `get_feed_urls()` | 已处理 |
| post comments feed | `purge.feed.comments` | `get_feed_comments_urls()` | 已处理 |
| author feed | `purge.feed.author` | `get_feed_author_urls()` | 已处理 |
| category/tag/taxonomy feed | `purge.feed.terms` | `get_feed_terms_urls()` | 已处理 |
| 配置的额外 pages | `purge.pages` 非空 | `get_pages_urls()` | 已处理 |
| search results | 无对应分支 | — | 代码中未发现 |
| arbitrary related posts/pages | 无对应分支 | — | 代码中未发现 |

“代码中未发现”不表示已发生故障。

## 8. 各类 URL 的 Host 来源

### 8.1 首页

`Util_PageUrls::get_frontpage_urls()`：

```php
$full_urls[] = get_home_url() . '/';
```

Host 来源：WordPress home URL。后台 Polylang 使用 `PLL_Filters_Links`，不注册 frontend 的 `home_url` filter，因此 admin 保存时这里是默认：

```text
https://www.shuijingwanwq.com/
```

它不依据被保存 post 的语言。

若 site path 与 home path 不同，W3TC 还可能加入 `site_url() . '/'`；当前两者 path 都是 `/`，不会因此加入 admin Host。

### 8.2 首页/归档 pagination

W3TC 临时把 `$_SERVER['REQUEST_URI']` 替换成目标 path。处于 admin 时走 `get_pagenum_link_admin()`，base 为：

```php
trailingslashit( get_bloginfo( 'url' ) )
```

Host 来源仍是 WordPress home URL，当前为 www。`REQUEST_URI` 只提供 path；当前 admin Host 不成为最终 base。

### 8.3 post permalink

`get_post_urls()` 调用：

```php
get_permalink( $post_id )
```

WordPress 对普通 post 应用 `post_link` filter。Polylang 的 `PLL_Filters_Links::post_type_link()` 在 frontend 和 admin 都注册，并明确使用：

```php
$this->model->post->get_language( $post->ID )
```

再由 `switch_language_in_link()` 切换 URL。

Host 来源：**被处理 post 自身语言**，不是当前 admin language 或 `HTTP_HOST`。

- zh post → www
- en post → en

post 内部分页直接在该 permalink 后追加路径，继承相同 Host。

### 8.4 category/tag/taxonomy archive

`get_post_terms_urls()` 调用：

```php
get_term_link( $term, $term->taxonomy )
```

Polylang 的 `term_link()` 在 admin 也注册。对 translated taxonomy，它使用：

```php
$this->model->term->get_language( $term->term_id )
```

Host 来源：**term 自身语言**。

但 W3TC 随后用默认 www 的 `home_domain_root_url()` 从 term link 中剥离 host，再交给 admin pagination helper。对 en 独立域名，www root 无法从 en URL 中剥离，可能构造 `www base + 完整 en URL` 的错误分页目标。这是静态代码风险；当前 `pgcache.purge.terms=false`，不会影响 21018/21022 当前官方队列。

### 8.5 author/date archive

W3TC 分别调用：

- `get_author_posts_url()`
- `get_day_link()`
- `get_month_link()`
- `get_year_link()`

Polylang 只在 `PLL_Frontend_Filters_Links` 中为 `author_link`、`day_link`、`month_link`、`year_link` 注册 `archive_link()`，其语言依据是当前 frontend `$curlang`。

后台使用基础 `PLL_Filters_Links`，没有这些 filters。Host 来源因此是默认 WordPress home（www），没有 post-specific language。

### 8.6 blog feed

W3TC 注释明确称 helper 为“remove filtering”，并自行构造：

```php
home_url( user_trailingslashit( $permalink, 'feed' ) )
```

Polylang 的 `feed_link` filter不会参与这个返回值；admin 中也没有 frontend `home_url` filter。

Host 来源：默认 WordPress home（www），不是 post 自身语言。

### 8.7 post comments feed

pretty permalink 下从 `get_permalink( $post_id )` 开始，因此 Host 来自 post 自身语言；随后追加 `/feed/`。

### 8.8 author feed

pretty permalink 下从 `get_author_posts_url()` 开始；admin 中无 Polylang author filter，因此 Host 是 www。

### 8.9 term feed

pretty permalink 下从 `get_term_link()` 开始，Polylang 按 term 自身语言过滤，随后直接追加 feed path，因此 Host 可为 www 或 en。

### 8.10 CPT archive

`get_post_type_archive_link()` 的 Polylang 基础 filter通常依赖当前 `$curlang`。Polylang cache compatibility 还在 `clean_post_cache` priority 1 添加一个按 post 自身语言切换 CPT archive 的 filter。第一条 CPT archive link因而有机会使用 post 语言。

和 term pagination 相同，W3TC 用默认 www `home_url()` 剥离 archive root；en 独立域名下的 pagination path 需要额外验证。

### 8.11 配置的额外 pages

W3TC 直接：

```php
get_home_url() . '/' . trim( $uri, '/' )
```

Host 来源是默认 WordPress home（www），不依据 post 或 term 语言。

## 9. Polylang URL filters 总结

Polylang 3.8.6 的 admin 使用 `PLL_Filters_Links`：

| WordPress URL API/filter | admin 是否有 Polylang filter | 语言依据 |
| --- | --- | --- |
| `get_permalink()` / `post_link` | 是 | post 自身语言 |
| CPT permalink / `post_type_link` | 是 | post 自身语言 |
| `get_term_link()` / `term_link` | 是 | term 自身语言 |
| `page_link` / `_get_page_link` | 是 | page 自身语言 |
| `post_type_archive_link` | 是 | 通常当前语言；cache compatibility 可按被清 post 语言追加 filter |
| `home_url` | admin 中无 frontend filter | WordPress home |
| `get_pagenum_link` | admin 中无 frontend filter；W3TC 使用自己的 admin helper | WordPress home + target path |
| author/date archive filters | admin 中无 | WordPress home |
| `feed_link` | admin 中无 frontend filter；W3TC blog feed还自行绕开该 filter | WordPress home |

必须区分：

- post/term link：由对象自身语言决定；
- home/author/date/blog feed：在 admin 中没有对象语言，落到默认 home；
- frontend `$curlang` filters：不能假设在 admin save request 中存在。

## 10. 旧架构基线

旧架构：

```text
zh: https://www.shuijingwanwq.com/
en: https://www.shuijingwanwq.com/en/
```

### 能由当前代码解释的部分

- 所有请求和 Redis item key 共用 `www` Host namespace。
- Core 从 www 后台保存时删除 `posts`/`post_meta` key并更新 `posts:last_changed`，会作用于 zh/en 共用的对象缓存空间。
- Polylang 把 en post/term link变为同一 www Host 下的 `/en/...` path。
- W3TC 用 www `home_domain_root_url()` 剥离 post/term URL root时成立，分页 helper 仍以 www 为 base，不会发生跨域名拼接。

这些特性与旧架构 Object Cache 和 post/term Page Cache 能自然工作一致。

### 当前静态代码不能解释的部分

在 admin save request 中，当前 W3TC 2.10.3 的原生首页 helper只加入默认 www 根首页，不会根据 en post 自动加入 `www/en/`；blog feed 同样使用默认 home。

因此，“旧架构整体缓存曾正常”不能仅凭当前版本源码证明“旧英文首页每次 save_post 都由 W3TC 原生自动精确 purge”。可能影响历史行为的内容包括旧版本、旧配置、当时管理入口、其他兼容逻辑或实际观察范围，均未在本轮验证。

历史正常是重要基线，但不能替代具体调用链证据。

## 11. 新架构行为变化

新架构：

```text
zh frontend: www
en frontend: en
admin entry: admin
```

已确认变化：

1. W3TC Redis item key从一个 www namespace 分裂为 www/en/admin 三个物理 Host namespace。
2. Core 在 admin 保存时的单 key删除和 `posts:last_changed` 更新只作用于 admin namespace。
3. post/term permalink 仍可由 Polylang 按对象语言生成正确 www/en Host。
4. 首页、首页 pagination、blog feed、author/date archive、额外 pages 在 admin 中仍以默认 www home 为基础。
5. en 独立域名不能再被“只处理 www Host”自然覆盖。
6. admin Host 是操作上下文，不是预期前台 Page Cache 目标。

## 12. 21018 静态推演

已知：

- post ID：21018
- language：zh
- permalink：`https://www.shuijingwanwq.com/2026/07/31/21018/`
- 保存入口：`https://admin.shuijingwanwq.com`

当前配置下 W3TC 官方预计队列：

| URL 类型 | 预计 URL | Host | Host 来源 |
| --- | --- | --- | --- |
| 首页 | `https://www.shuijingwanwq.com/` | www | `get_home_url()` / WordPress home |
| 首页 pagination | `https://www.shuijingwanwq.com/page/2/` 至配置/实际页数上限（最多 page 10） | www | W3TC admin paginator 的 `get_bloginfo( 'url' )` |
| post permalink | `https://www.shuijingwanwq.com/2026/07/31/21018/` | www | `get_permalink(21018)` + Polylang post language zh |
| post 内分页 | 若存在 `<!--nextpage-->`，在该 permalink 后追加页码 | www | 继承 post permalink |
| blog RSS2 feed | `https://www.shuijingwanwq.com/feed/` | www | W3TC helper的 `home_url()` |
| category/tag/taxonomy | 当前配置不加入 | — | `pgcache.purge.terms=false` |
| author/date archive | 当前配置不加入 | — | 对应配置均 false |
| comments/author/term feed | 当前配置不加入 | — | 对应配置均 false |

`admin.shuijingwanwq.com` 不进入前台 Page Cache purge 目标。

官方预计清理的 Host：**www**。

## 13. 21022 静态推演

已知：

- post ID：21022
- language：en
- permalink：`https://en.shuijingwanwq.com/2026/07/31/21022/`
- 保存入口：`https://admin.shuijingwanwq.com`

当前配置下 W3TC 官方预计队列：

| URL 类型 | 预计 URL | Host | Host 来源 |
| --- | --- | --- | --- |
| 首页 | `https://www.shuijingwanwq.com/` | www | `get_home_url()` / WordPress default home；不依据 post language |
| 首页 pagination | `https://www.shuijingwanwq.com/page/2/` 至配置/实际页数上限（最多 page 10） | www | W3TC admin paginator 的 `get_bloginfo( 'url' )` |
| post permalink | `https://en.shuijingwanwq.com/2026/07/31/21022/` | en | `get_permalink(21022)` + Polylang post language en |
| post 内分页 | 若存在 `<!--nextpage-->`，在 en permalink 后追加页码 | en | 继承 post permalink |
| blog RSS2 feed | `https://www.shuijingwanwq.com/feed/` | www | W3TC helper的 `home_url()` |
| category/tag/taxonomy | 当前配置不加入 | — | `pgcache.purge.terms=false` |
| author/date archive | 当前配置不加入 | — | 对应配置均 false |
| comments/author/term feed | 当前配置不加入 | — | 对应配置均 false |

原生队列中：

- en Host：英文 post permalink（及可能的 post 内分页）；
- www Host：默认首页、首页 pagination、blog feed；
- admin Host：没有前台 Page Cache目标；
- **`https://en.shuijingwanwq.com/`：代码中未进入当前官方队列。**

## 14. admin Host 是否参与 Page Cache目标

当前 standard post + 当前 purge 配置下，admin Host 不进入最终 Page Cache URL：

- post URL由对象语言变为 www/en；
-首页与 feed由 WordPress home变为 www；
-首页 pagination base也为 www；
- home/site URI path 都为 `/`，W3TC 不追加单独的 `site_url()` root。

admin 的作用是决定当前执行上下文，并影响 W3TC Object Cache 的物理 Host namespace；它不是第三个前台页面 purge 目标。

## 15. Object Cache 官方行为

### 15.1 WordPress Core

WordPress `wp_insert_post()` 在触发 `save_post` 之前调用：

```php
clean_post_cache( $post_id );
```

`clean_post_cache()`：

- `wp_cache_delete( $post->ID, 'posts' )`
- 删除 `post_parent:ID`（`posts` group）
- `wp_cache_delete( $post->ID, 'post_meta' )`
- `clean_object_term_cache()`
- 删除 `wp_get_archives`（`general` group）
- page 时删除 `all_page_ids`
- `wp_cache_set_posts_last_changed()`，即更新 `last_changed`（`posts` group）

所以 save 本身已经触发 Core 对象失效。

### 15.2 W3TC 官方是否额外 flush `posts` group

没有。

W3TC 官方注册到 `w3tc_flush_post` 的模块包括 Page Cache，以及在启用时的 Varnish、Cloudflare/CDN integrations；Object Cache 模块没有在这个 action 上注册 `posts` group flush。

跨 Host `wp_cache_flush_group( 'posts' )` 属于当前自定义 MU Plugin 行为，不是本轮原生流程。

### 15.3 W3TC Redis key 模型

WordPress 逻辑 key 对 post object 是：

```text
group = posts
id = post ID
```

W3TC `ObjectCache_WpObjectCache_Regular::_get_cache_key()` 组合：

```text
blog_id + group + id
```

Redis engine的最终 item storage key由 `Cache_Base::get_item_key()` 组合：

```text
w3tc_{instance_id}_{host}_{blog_id}_{module}_{logical_key}
```

其中 `host` 来自：

```php
Util_Environment::host()
→ $_SERVER['HTTP_HOST']
```

因此单个 post object item、post meta、`posts:last_changed` 等读写/删除都会落入当前请求 Host 的 item namespace。

Redis group-version key则由：

```text
w3tc_{instance_id}_{blog_id}_{module}_{group}_key_version
```

组成，不含 Host。调用 group flush 会递增共享 group version，但 W3TC 原生 post save 不执行这个 group flush。

### 15.4 是否存在 value/context key collision

普通 `WP_Post` 对象本身来自共享数据库，通常不应因 Host/语言产生不同内容；当前没有发现“value 依赖 Host/language，但逻辑 key 缺少上下文”的 post object collision。

当前确认的是另一种问题：**相同共享对象被 W3TC按 Host 复制存储，而 Core 单 key失效只删除当前 admin Host 的副本。**

Polylang 查询结果可依赖语言；旧单 Host 架构下 Polylang/WordPress 的查询 SQL/cache key机制曾正常。新架构额外增加了物理 Host namespace，使 admin 中更新的 `posts:last_changed` 无法自然使 www/en 的 query namespace失效。

不需要据此设计三套对等 Redis。后续真实 admin Update已经确认：现有
MU Plugin通过共享 `posts` group version把失效传播到前台 Host。

## 16. 已确认差异

| 机制 | 旧 www + `/en/` | 新 www + en + admin | 结论 |
| --- | --- | --- | --- |
| Redis item Host namespace | zh/en/admin 操作可共用 www | admin/www/en 分离 | Core admin 单 key失效不再覆盖前台副本 |
| post permalink | 同 www，以 path 区分语言 | Polylang按 post language切换 www/en | 新架构仍正确 |
| term permalink | 同 www root可安全剥离 | en term与默认 www root不同 | term pagination存在静态拼接风险；当前 terms purge关闭 |
| 首页 | 默认 www root | admin 中仍为默认 www | en 首页不进入英文 post的原生队列 |
| blog feed | 默认 www feed | admin 中仍为默认 www feed | en feed不按 post language进入队列 |
| author/date archive | admin 中无对象语言 | admin 中仍无对象语言 | 多域名下不能自然选择 en；当前配置关闭 |
| admin 前台 Page Cache | 不适用/同 www入口 | 不应成为前台目标 | 当前 standard post队列未加入 admin URL |

## 17. 尚未确认部分

- 旧架构具体使用的 W3TC/Polylang版本、purge配置和管理入口。
- 旧 `/en/` 首页在每次英文 post save 后具体由哪个机制失效。
- `w3tc_preflush_post` / `w3tc_flushable_post` 在历史具体请求中是否被其他代码改变。
- 21018/21022 当次发布的官方 queued URL日志。
- term/CPT pagination 的跨域名静态风险是否能在启用对应 purge配置时运行时复现。
- 原生机制单独运行时，www/en中现存 post object或 query key是否会在某次
  admin save后实际保留旧值；当前真实 Update启用了现有 MU Plugin，因此验证
  的是补偿后结果。

这些未确认点不改变当前源码层面的两个边界：英文首页缺席于当前原生队列，以及 Core单 key失效局限于 admin Host namespace。

后续生产运行时验证已读取精确共享 key
`w3tc_2889287881_0_object_posts_key_version`，并观察到 21022真实 admin
Update使其从 `803` 推进到 `805`；同时 en首页 Page Cache在 Update后、
Origin GET前不存在。详见 `phase-1-post-save-runtime-validation.md`。
