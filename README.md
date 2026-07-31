# WordPress Polylang / W3 Total Cache Compatibility

## 项目背景

本项目源自一个 WordPress 多语言生产环境的缓存一致性需求。该环境使用：

- Polylang 多域名语言模式；
- W3 Total Cache Page Cache 与 Object Cache；
- Redis；
- 中文站 EdgeOne CDN；
- 英文站 Cloudflare CDN。

当前站点包括：

- 中文站：<https://www.shuijingwanwq.com>
- 英文站：<https://en.shuijingwanwq.com>
- 后台：<https://admin.shuijingwanwq.com>

## 实施阶段

### Phase 1：Origin Cache Consistency

当前只推进 Phase 1，目标是分析和改善服务器内部的缓存一致性，包括：

- WordPress 与 Polylang 运行时；
- W3 Total Cache Page Cache 与 Object Cache；
- Redis Persistent Object Cache；
- www、en、admin 多 Host 行为；
- Nginx / Origin HTTP 最终输出。

Phase 1 完全忽略公网 CDN 响应，验证以正确 Host 和 TLS SNI 的源站直连请求为准。

### Phase 2：CDN Cache Invalidation

Phase 2 当前延期，待 Phase 1 稳定后再处理：

- www / EdgeOne；
- en / Cloudflare；
- 精确 URL purge、CDN TTL、失败重试和凭据管理；
- CDN 与 Origin 的责任边界。

文章 21018 / 21022 已形成一个 Phase 2 真实测试案例：数据库、源站和当前 W3TC Page Cache 正确，但取证时公网 CDN 仍返回旧首页。本项目保留该证据，但当前不继续调查或实施 CDN purge。

## 项目目标

本项目的首要目标是尽快恢复和维持 WordPress 多语言 Origin 缓存一致性，而不是开发新的缓存插件。实施优先级是：

1. WordPress、Polylang、W3 Total Cache、Yoast SEO 官方机制；
2. 已安装插件提供的 Extensions 与配置；
3. 小范围、可回滚的配置调整；
4. 只有确认现成方案无法覆盖的最小缺口，才考虑 MU Plugin。

本仓库用于保存调查、配置和验证记录，必要时才保存补丁代码。即使 `src/` 最终没有新的 PHP 插件，也视为成功。

## 当前状态

Phase 1 正在进行，Phase 2 已延期。文章发布/更新场景下的核心
multi-Host Origin cache invalidation 已完成首次生产验证，但这不表示
Phase 1 全部完成或所有缓存问题已经解决。

当前 W3TC 2.10.3 已确认存在两个 multi-Host 原生缺口：

1. 从独立 admin Host保存英文 `post` 时，原生 Page Cache队列包含英文
   permalink，但缺少 en homepage；
2. WordPress Core的精确 Object Cache失效只作用于当前 admin Host的
   W3TC Redis物理 namespace，不能自然失效 www/en中的已有副本。

当前生产 MU Plugin `swq-w3tc-polylang-purge.php` 已静态确认并通过一次
真实生产 Update验证对这两个缺口进行补偿：

- 根据 `pll_get_post_language()` 与 `pll_home_url()` 取得文章所属语言首页，
  再调用 `w3tc_flush_url()`。英文文章 21022通过
  `admin.shuijingwanwq.com` 更新后，en首页 Page Cache文件在首次 Origin
  GET前已经不存在，确认 purge实际生效；
- 调用 `wp_cache_flush_group( 'posts' )` 推进不含 Host的共享
  `posts` group version。同一次真实 Update中，版本从 `803` 变为 `805`，
  确认 www/en/admin旧 generation已失效。

因此，这两个原生缺口目前均为“静态已确认补偿 + 运行时已确认补偿”。
生产参考版本继续保留，不扩展、不重写，也不开发第二个插件。

term、category、tag、menu、options、`wp_template`、`wp_template_part`、
Global Styles和 Theme JSON尚未主动进行生产运行时验证；目前没有足够真实
故障证据，不把它们列为待修 Bug。`options` group兼容逻辑存在，但生产必要性
仍尚未确认。

当前暂停扩大 W3TC源码研究和开发新插件。生产插件是否适合通用公开部署仍需
后续整理；Redis/Object Cache也未被认定为所有故障的根因。CDN purge尚未
实现，21018/21022曾出现的公网旧 HIT继续归入延期的 Phase 2。
