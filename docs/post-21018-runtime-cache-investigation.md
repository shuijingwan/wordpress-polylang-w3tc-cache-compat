# Post 21018 运行时缓存取证

## 1. 范围、时间与限制

- 取证日期：2026-07-31（服务器时区 `+08:00`）。
- WordPress：`/data/wwwroot/www.shuijingwanwq.com`
- 目标文章：`21018`
- 生产 MU Plugin：`wp-content/mu-plugins/swq-w3tc-polylang-purge.php`
- W3 Total Cache：`2.10.3`
- 全程未执行 cache flush、Redis 写入/删除、数据库修改或配置修改。
- 公网请求没有添加随机 query string。
- 源站请求使用正确 TLS Host 和 `--resolve HOST:443:127.0.0.1`。
- WP-CLI 不在服务器 `PATH` 中找到 PHP；实际只读命令使用 `/usr/local/php/bin/php /usr/local/bin/wp`。
- MySQL CLI 不在 `PATH`，所以精确 SQL 通过 WordPress 已有数据库连接和 `$wpdb` 执行。

注意：源站 GET 是任务要求的 HTTP 观察操作。两次源站 GET 分别在 `11:35:04` 和 `11:35:16 +08:00` 生成了当前 W3TC 首页缓存文件；因此本文能确认请求后的当前文件状态，不能恢复源站 GET 之前这些文件是否存在及其内容。

## 2. Database

### 文章 21018

执行的查询：

```sql
SELECT ID, post_status, post_type, post_date, post_modified, post_title
FROM wp_posts
WHERE ID = 21018;
```

实际结果：

| ID | post_status | post_type | post_date | post_modified | post_title |
| ---: | --- | --- | --- | --- | --- |
| 21018 | publish | post | 2026-07-31 11:09:12 | 2026-07-31 11:09:15 | WordPress 三域名架构再次踩坑：Adsterra 归档广告不生效，最终定位到 W3TC Object Cache |

### 最新 8 篇已发布 post

执行的查询等价于：

```sql
SELECT ID, post_date, post_title
FROM wp_posts
WHERE post_type = 'post'
  AND post_status = 'publish'
ORDER BY post_date DESC
LIMIT 8;
```

实际结果：

| ID | post_date | post_title |
| ---: | --- | --- |
| 21022 | 2026-07-31 11:09:12 | WordPress Three-Domain Architecture Pitfalls: Adsterra Archive Ads Fail to Work, Traced to W3TC Object Cache |
| 21018 | 2026-07-31 11:09:12 | WordPress 三域名架构再次踩坑：Adsterra 归档广告不生效，最终定位到 W3TC Object Cache |
| 20910 | 2026-07-30 21:34:38 | Another Protected Token Validation Failure in GLM-5.2 Whole-Article Translation: From Missing Tail Tokens to Plaintext Structure Reordering |
| 20906 | 2026-07-30 21:34:38 | GLM-5.2 整篇翻译再次出现 Protected Token 校验失败：从尾部 Token 丢失到 Plaintext 结构重排的完整排查记录 |
| 20902 | 2026-07-30 19:59:03 | ThinkPad T570 Fails to Detect External Display via Type-C to VGA on Ubuntu: A Complete Troubleshooting Log from BIOS and UCSI to Thunderbolt |
| 20895 | 2026-07-30 19:59:03 | ThinkPad T570 在 Ubuntu 下 Type-C 转 VGA 无法识别副屏：从 BIOS、UCSI 到 Thunderbolt 的完整排查记录 |
| 20889 | 2026-07-30 15:48:26 | Ubuntu ThinkPad T570 Black Screen After Suspend: Disable Auto-Suspend and Lid Close to Avoid Frequent Force Shutdowns |
| 20878 | 2026-07-30 15:48:26 | Ubuntu ThinkPad T570 挂起后黑屏：最终关闭自动挂起与合盖挂起，避免频繁强制关机 |

结论：数据库已包含 21018 及其英文文章 21022，二者在相同 `post_date` 下位于最新结果最前。

## 3. Polylang 与文章对象

文章 21018 的 WP-CLI 只读结果：

| 项目 | 结果 |
| --- | --- |
| `get_post( 21018 )` | 返回 `WP_Post`；`ID=21018`、`post_status=publish`、`post_type=post`、`post_date=2026-07-31 11:09:12` |
| `pll_get_post_language( 21018 )` | `zh` |
| `pll_get_post_translations( 21018 )` | `{"zh":21018,"en":21022}` |
| `get_permalink( 21018 )` | `https://www.shuijingwanwq.com/2026/07/31/21018/` |

英文关联文章：

| ID | language | status | post_date | permalink |
| ---: | --- | --- | --- | --- |
| 21022 | en | publish | 2026-07-31 11:09:12 | `https://en.shuijingwanwq.com/2026/07/31/21022/` |

结论：21018 的语言和中英文翻译关系完整，英文关联文章存在且已发布。

## 4. 三个 Host 的 WP-CLI / WP_Query

三个 URL 上下文均执行：

```php
new WP_Query(
    array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 8,
    )
);
```

### URL 与语言上下文

| `--url` | `HTTP_HOST` | `home_url()` | `site_url()` | `pll_current_language()` |
| --- | --- | --- | --- | --- |
| `https://www.shuijingwanwq.com` | `www.shuijingwanwq.com` | `https://www.shuijingwanwq.com` | `https://www.shuijingwanwq.com` | `false` |
| `https://en.shuijingwanwq.com` | `en.shuijingwanwq.com` | `https://www.shuijingwanwq.com` | `https://en.shuijingwanwq.com` | `false` |
| `https://admin.shuijingwanwq.com` | `admin.shuijingwanwq.com` | `https://www.shuijingwanwq.com` | `https://admin.shuijingwanwq.com` | `false` |

### 查询结果

三个 Host 的 WP-CLI 结果完全相同：

| 顺序 | ID | post_date | post_title |
| ---: | ---: | --- | --- |
| 1 | 21022 | 2026-07-31 11:09:12 | WordPress Three-Domain Architecture Pitfalls: Adsterra Archive Ads Fail to Work, Traced to W3TC Object Cache |
| 2 | 21018 | 2026-07-31 11:09:12 | WordPress 三域名架构再次踩坑：Adsterra 归档广告不生效，最终定位到 W3TC Object Cache |
| 3 | 20910 | 2026-07-30 21:34:38 | Another Protected Token Validation Failure in GLM-5.2 Whole-Article Translation: From Missing Tail Tokens to Plaintext Structure Reordering |
| 4 | 20906 | 2026-07-30 21:34:38 | GLM-5.2 整篇翻译再次出现 Protected Token 校验失败：从尾部 Token 丢失到 Plaintext 结构重排的完整排查记录 |
| 5 | 20902 | 2026-07-30 19:59:03 | ThinkPad T570 Fails to Detect External Display via Type-C to VGA on Ubuntu: A Complete Troubleshooting Log from BIOS and UCSI to Thunderbolt |
| 6 | 20895 | 2026-07-30 19:59:03 | ThinkPad T570 在 Ubuntu 下 Type-C 转 VGA 无法识别副屏：从 BIOS、UCSI 到 Thunderbolt 的完整排查记录 |
| 7 | 20889 | 2026-07-30 15:48:26 | Ubuntu ThinkPad T570 Black Screen After Suspend: Disable Auto-Suspend and Lid Close to Avoid Frequent Force Shutdowns |
| 8 | 20878 | 2026-07-30 15:48:26 | Ubuntu ThinkPad T570 挂起后黑屏：最终关闭自动挂起与合盖挂起，避免频繁强制关机 |

限制：`pll_current_language()` 在三个 WP-CLI 上下文均为 `false`，所以这组 WP_Query 没有模拟出真实前台的 Polylang 语言过滤，而是返回中英文混合列表。它能证明 PHP 查询层看到了最新记录，但不能单独证明三个 Host 的语言过滤查询都正常，也不能从输出确认结果来自数据库还是 Persistent Object Cache。

## 5. 公网首页（CDN）

HTML 和 headers 保存于本地 `/tmp/post-21018-public-HOST.{headers,html}`。

### www / EdgeOne

- HTTP：`200`
- 响应时间：`Fri, 31 Jul 2026 03:34:37 GMT`
- `server: nginx`
- `eo-cache-status: HIT`
- `eo-log-uuid: 3922642505938392544`
- HTML 大小：`273322` bytes
- HTML 不包含 `/21018/` 或 `/21022/`
- 最前文章：`20541`，`2026-07-28`

最前 8 个不重复文章链接：

1. `https://www.shuijingwanwq.com/2026/07/28/20541/`
2. `https://www.shuijingwanwq.com/2026/07/28/20530/`
3. `https://www.shuijingwanwq.com/2026/07/28/20518/`
4. `https://www.shuijingwanwq.com/2026/07/28/20507/`
5. `https://www.shuijingwanwq.com/2026/07/28/20488/`
6. `https://www.shuijingwanwq.com/2026/07/28/20461/`
7. `https://www.shuijingwanwq.com/2026/07/27/20445/`
8. `https://www.shuijingwanwq.com/2026/07/27/20431/`

### en / Cloudflare

- HTTP：`200`
- 响应时间：`Fri, 31 Jul 2026 03:34:39 GMT`
- `server: cloudflare`
- `cf-cache-status: HIT`
- `age: 163252`
- `cache-control: max-age=14400`
- `cf-ray: a2398c71799f4adb-LAX`
- HTML 大小：`280331` bytes
- HTML 不包含 `/21018/` 或 `/21022/`
- 最前文章：`20643`，`2026-07-29`

最前 8 个不重复文章链接：

1. `https://en.shuijingwanwq.com/2026/07/29/20643/`
2. `https://en.shuijingwanwq.com/2026/07/29/20628/`
3. `https://en.shuijingwanwq.com/2026/07/29/20617/`
4. `https://en.shuijingwanwq.com/2026/07/28/20552/`
5. `https://en.shuijingwanwq.com/2026/07/28/20535/`
6. `https://en.shuijingwanwq.com/2026/07/28/20526/`
7. `https://en.shuijingwanwq.com/2026/07/28/20514/`
8. `https://en.shuijingwanwq.com/2026/07/28/20502/`

结论：取证时两个公网首页均明确返回 CDN HIT，且内容落后于数据库。

## 6. 源站首页

服务器本机响应保存于 `/tmp/post-21018-origin-HOST.{headers,html}`。

### www

- 请求：`curl --resolve www.shuijingwanwq.com:443:127.0.0.1 https://www.shuijingwanwq.com/`
- HTTP：`200`
- `server: nginx`
- HTML 大小：`278399` bytes
- 首篇：`https://www.shuijingwanwq.com/2026/07/31/21018/`
- HTML 中 `/21018/` 出现 2 行；不包含 `/21022/`

前 8 个不重复文章 ID：

`21018, 20906, 20895, 20878, 20862, 20753, 20742, 20634`

### en

- 请求：`curl --resolve en.shuijingwanwq.com:443:127.0.0.1 https://en.shuijingwanwq.com/`
- HTTP：`200`
- `server: nginx`
- HTML 大小：`282945` bytes
- 首篇：`https://en.shuijingwanwq.com/2026/07/31/21022/`
- HTML 中 `/21022/` 出现 2 行；不包含 `/21018/`

前 8 个不重复文章 ID：

`21022, 20910, 20902, 20889, 20875, 20764, 20748, 20643`

结论：取证时源站的两个实际前台响应内容和语言分离均正常。

## 7. W3TC Page Cache

只读配置：

| 配置 | 值 |
| --- | --- |
| `pgcache.enabled` | `true` |
| `pgcache.engine` | `file_generic` |
| `pgcache.purge.home` | `true` |
| `pgcache.purge.front_page` | `false` |
| `pgcache.purge.post` | `true` |
| Cache 根目录 | `/data/wwwroot/www.shuijingwanwq.com/wp-content/cache` |
| Page Cache 目录 | `/data/wwwroot/www.shuijingwanwq.com/wp-content/cache/page_enhanced` |

已可靠定位两个 HTTPS 首页缓存文件：

| Host | 文件 | mtime | size | 首篇 | 目标文章 |
| --- | --- | --- | ---: | --- | --- |
| www | `page_enhanced/www.shuijingwanwq.com/_index_slash_ssl.html` | `2026-07-31 11:35:04.603322858 +0800` | 278399 | 21018 | 包含 21018，不包含 21022 |
| en | `page_enhanced/en.shuijingwanwq.com/_index_slash_ssl.html` | `2026-07-31 11:35:16.112457410 +0800` | 282945 | 21022 | 包含 21022，不包含 21018 |

这些文件的 mtime、size 和内容分别与两次源站请求一致，说明它们是源站取证 GET 时生成的当前首页缓存文件。当前文件正常；取证没有证据恢复 GET 前的文件状态。

## 8. Object Cache 最小证据

只读配置与能力：

| 项目 | 值 |
| --- | --- |
| `objectcache.enabled` | `true` |
| `objectcache.engine` | `redis` |
| Redis servers | `127.0.0.1:6379` |
| Redis DB | `0` |
| Persistent connection | `true` |
| Timeout | `0` |
| `wp_using_ext_object_cache()` | www/en/admin 均为 `true` |
| `wp_cache_supports( 'flush_group' )` | www/en/admin 均为 `true` |

生产 MU Plugin 的具体实现：

- `w3tc_flush_post`、优先级 1250：
  - 仅接受 `post_type === 'post'`；
  - 每个 PHP 请求最多执行一次；
  - 调用 `wp_cache_flush_group( 'posts' )`。
- `w3tc_flush_after_objectcache`、优先级 10：
  - 调用 `wp_cache_flush_group( 'options' )`。

本次没有读取、修改或删除 Redis key，也没有 flush。三个 WP-CLI 查询结果相同且能看到最新文章，但 `pll_current_language()` 为 `false`，同时没有安全的 cache-hit provenance，所以不能确认各 Host 的查询是从 Persistent Object Cache 命中还是从数据库生成。源站前台 HTML 当前正确，说明 Object Cache 没有在取证时阻止两个首页生成正确内容；这不等同于证明发布当时 Object Cache 从未异常。

## 9. `w3tc_flush_post` 的真实触发机制

### 唯一 action 派发位置

精确搜索 W3TC 插件目录只找到一处：

`CacheFlush_Locally.php:261`

```php
public function flush_post( $post_id, $force = false, $extras = null ) {
    $do_flush = apply_filters( 'w3tc_preflush_post', true, $extras );
    if ( $do_flush ) {
        do_action( 'w3tc_flush_post', $post_id, $force, $extras );
    }
}
```

参数依次为：

1. `$post_id`
2. `$force`，默认 `false`
3. `$extras`，默认 `null`

`w3tc_preflush_post` 可以阻止 action 派发。

### 普通 WordPress 发布路径

W3TC Page Cache 模块运行时，`PgCache_Plugin.php`：

- 在 `w3tc_flush_post` 优先级 1100 注册 Page Cache 的 post flush；
- 调用 `Util_AttachToActions::flush_posts_on_actions()`。

后者在 `Util_AttachToActions.php` 注册：

```php
add_action( 'save_post', array( $w3tc_o, 'on_post_change' ), 0, 2 );
```

`on_post_change()` 获取 W3TC 配置，调用：

```php
if ( Util_Environment::is_flushable_post( $post, 'posts', $w3tc_config ) ) {
    $cacheflush->flush_post( $post_id );
}
```

`is_flushable_post()` 默认要求：

- post 是对象；
- post type 不是 `revision` 或 `attachment`；
-状态为 `publish`；特定配置下也可允许 `private`；
- `w3tc_flushable_post` filter 仍可改变结果。

因此，按当前 W3TC 2.10.3 代码和已启用的 Page Cache，普通已发布文章的 `save_post` 路径确实会进入 W3TC `flush_post()`，并在 filters 未阻止时触发 `w3tc_flush_post`。该 hook 并非只由手工 purge 按钮触发。

运行时还确认当前 MU Plugin 的三个 callback 已注册：

| Callback | Hook | Priority |
| --- | --- | ---: |
| `swq_w3tc_polylang_queue_language_home` | `w3tc_flush_post` | 1200 |
| `swq_w3tc_sync_posts_object_cache_group` | `w3tc_flush_post` | 1250 |
| `swq_w3tc_sync_options_group_after_objectcache_flush` | `w3tc_flush_after_objectcache` | 10 |

边界：没有 21018 发布请求当时的 hook 日志，不能仅凭当前代码证明那一次请求中 filters 未阻止 action、所有 callback 均执行完成、或外部 CDN 收到了 purge。

### 外部 CDN

当前 W3TC 配置：

| 配置 | 值 |
| --- | --- |
| `cdn.enabled` | `false` |
| `cdn.engine` | 空字符串 |
| `extensions.active` | 空数组 |

生产 MU Plugin 的 `w3tc_flush_url()` 清理的是 W3TC URL/Page Cache 路径；插件没有 EdgeOne 或 Cloudflare API 调用。当前 W3TC 配置也没有启用 W3TC CDN 模块或 extension。本文没有检查 CDN 控制台规则或其他系统是否负责 purge。

## 10. 证据矩阵

矩阵中的判断严格限定于本次取证时刻。

| 层级 | www | en | 证据 | 当前判断 |
| --- | --- | --- | --- | --- |
| 1. Database | 已确认正常 | 已确认正常 | 21018 与 21022 均为 publish，日期为 2026-07-31 11:09:12，位于最新 SQL 结果最前 | 已确认正常 |
| 2. WordPress PHP / WP_Query | 尚未确认 | 尚未确认 | 三 Host 的 WP-CLI 查询都看到 21022/21018，但 `pll_current_language()` 均为 false，未复现真实语言过滤；来源是否命中 Persistent Object Cache也未知 | 尚未确认 |
| 3. Polylang | 已确认正常 | 已确认正常 | 21018=zh，translations 为 zh:21018/en:21022；源站 HTML 分别输出正确语言文章 | 已确认正常 |
| 4. Object Cache | 尚未确认 | 尚未确认 | Redis Object Cache 和 flush_group 已启用；无安全 cache-hit provenance；当前源站输出正确 | 尚未确认 |
| 5. W3TC Page Cache | 已确认正常 | 已确认正常 | 当前首页缓存文件首篇分别为 21018/21022；文件在源站 GET 时生成 | 已确认正常 |
| 6. Origin HTTP | 已确认正常 | 已确认正常 | `--resolve ...:127.0.0.1` 均返回 200，首篇分别为 21018/21022 | 已确认正常 |
| 7. CDN HTTP | 已确认异常 | 已确认异常 | EdgeOne HIT 首篇 20541；Cloudflare HIT、Age 163252、首篇 20643；均不含目标新文章 | 已确认异常 |

## 11. 当前故障边界

- 本次最早能够**确认异常**的层级是 CDN HTTP。
- 数据库、Polylang 关系、当前 W3TC Page Cache 文件和当前 Origin HTTP 均有正常证据。
- WordPress WP-CLI 的 Host 语言上下文和 Object Cache 命中来源仍未确认，但源站前台实际渲染在取证时是正确的。
- 不能把本次故障归因于 Object Cache。
- 能确认公网内容被两个 CDN 的旧 HIT 隔离在源站新内容之外；尚未从 CDN 控制台或 purge 日志确认“为什么没有失效”。
