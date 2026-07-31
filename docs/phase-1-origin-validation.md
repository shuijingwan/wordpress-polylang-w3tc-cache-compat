# Phase 1 Origin 验证方法

## 核心原则

- Phase 1 以服务器本机 Origin 请求为最终 HTTP 证据。
- 保持真实 Host 与 TLS SNI：

```bash
curl --resolve www.shuijingwanwq.com:443:127.0.0.1 \
  https://www.shuijingwanwq.com/

curl --resolve en.shuijingwanwq.com:443:127.0.0.1 \
  https://en.shuijingwanwq.com/
```

- 不使用公网 EdgeOne/Cloudflare 响应判断 Phase 1 是否成功。
- 不用随机 query string替代真实缓存路径验证。
- 每次取证标明时间、Host、目标对象、缓存文件 mtime 和各层实际值。
- 验证不默认授权 cache flush、Redis 修改或数据库修改。

## 分层验证顺序

### 1. Database

用精确只读 SQL 确认目标对象的数据库真值：

- post ID/type/status/date/modified/content 或标题；
- term/taxonomy/relationship；
- option value；
- Block Theme post type 及修改时间；
- Polylang language/translation metadata。

只查询目标 ID/key，不扫描无关数据。

### 2. WordPress PHP 与 WP-CLI

针对 www、en、admin 分别设置 `--url`，检查：

- `HTTP_HOST`
- `home_url()`
- `site_url()`
- `get_post()`
- `get_term()`
- `get_option()`
- `WP_Query`
- Polylang API

将 PHP 返回值与数据库真值对照。不能只看到数据库正确就认定 Object Cache 正常。

### 3. WP-CLI / Polylang 限制

已有取证确认，普通：

```text
--url=https://www.shuijingwanwq.com
--url=https://en.shuijingwanwq.com
--url=https://admin.shuijingwanwq.com
```

执行相同 `WP_Query` 时，三个上下文都返回混合列表：

```text
21022
21018
...
```

且 `pll_current_language()` 均为 `false`。

因此，普通 `WP-CLI --url` 只设置 URL/Host，并不自动等价于真实前台 Polylang 请求。涉及语言过滤时：

- 不人为切换语言后把结果冒充前台行为；
- 记录 `pll_current_language()`；
- 使用真实 Origin HTTP/HTML 验证前台语言输出；
- 如需 PHP 级语言证据，应设计能够真实经过前台 request lifecycle 的只读取证，而不是只依赖 `wp eval`。

### 4. Object Cache / Redis

只读确认：

- W3TC Object Cache 开关与 engine；
- Redis 非密码连接参数；
- `wp_using_ext_object_cache()`；
- `wp_cache_supports( 'flush_group' )`；
- 目标对象在三个 Host 的 PHP 返回值是否不同；
- 在能够可靠映射时，读取目标 Redis key、group version 或 cache-hit provenance。

不要为了证明缓存存在而写 key、删除 key 或 flush。

### 5. W3TC Page Cache

只对目标 Host/URL：

- 确认 Page Cache 开关、engine 和目录；
- 可靠映射缓存文件；
- 记录 path、mtime、size；
- 提取目标 ID/URL、canonical、hreflang、`<html lang>` 和内部链接；
- 与同一时间的 Origin HTTP HTML 对照。

如果无法确认 URL 到文件的映射，写“尚未确认”，不猜测。

### 6. Origin HTTP

服务器本机使用 `--resolve HOST:443:127.0.0.1`，保存 headers 与 HTML 到 `/tmp`，至少记录：

- HTTP 状态；
- Host 与 URL；
- `server`、`vary`、cache 相关 Origin headers；
- 页面核心对象 ID/URL；
- 请求前后对应 W3TC 文件状态。

注意：一次 GET 可能生成缺失的 W3TC Page Cache 文件。必须记录这一影响，不能把 GET 后生成的新文件描述为请求前状态。

### 7. HTML 正确性

至少检查：

- www 首页；
- en 首页；
- 中文文章；
- 英文对应文章；
- 中文分类；
- 英文分类；
- 中文标签；
- 英文标签；
- canonical；
- hreflang；
- `<html lang>`；
- 内部链接；
- 中英文文章/term 关系。

`<html lang>` 是验证项，不是当前已知故障。

## 事件验证

对未来获得授权的内容变更，应保留：

1. 变更前各层快照；
2. 触发事件、对象 ID、时间和 Host；
3. `w3tc_flush_post` 或其他 hook 的实际日志；
4. 变更后 Database、PHP、Object Cache、Page Cache、Origin HTTP 对照；
5. 不执行 flush 时是否能够自然达到一致；
6. 若失败，最早发生差异的层级。

当前已从 W3TC 2.10.3 源码确认普通已发布内容的 `save_post` 会在 filters 允许时进入 `w3tc_flush_post`。但无历史 hook 日志时，不能声称某次发布的所有回调一定成功。
