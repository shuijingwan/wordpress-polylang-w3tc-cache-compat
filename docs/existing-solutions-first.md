# 现成方案优先：Phase 1

## 目标与边界

目标是以最低维护成本解决 Origin Cache Consistency，不预设需要开发公共插件。

本轮只读盘点：

- W3 Total Cache 2.10.3；
- Polylang 3.8.6；
- Yoast SEO 28.1；
- 当前 W3TC Extensions；
- Polylang 与 Yoast 的官方兼容层。

不启用或停用功能，不修改设置、生产 MU Plugin、缓存、Redis、数据库、Nginx 或 CDN。

## 当前安装状态

| 插件 | slug | 版本 | 状态 |
| --- | --- | --- | --- |
| W3 Total Cache | `w3-total-cache` | 2.10.3 | active |
| Polylang | `polylang` | 3.8.6 | active |
| Yoast SEO | `wordpress-seo` | 28.1 | active |

当前 W3TC：

```text
extensions.active=[]
```

包括 `wordpress-seo` 在内，没有 W3TC Extension处于 active 状态。

## W3TC Yoast SEO Extension

### 是否存在

存在。

- Extension ID：`wordpress-seo`
- UI 名称：`Yoast SEO`
- W3TC 内部版本：`0.1`
- 文件：`Extension_WordPressSeo_Plugin.php`
- 管理文件：`Extension_WordPressSeo_Plugin_Admin.php`
- 当前状态：inactive
- 可用条件：定义了 `WPSEO_VERSION`；当前 Yoast active，条件满足。

### Extension 实际作用

W3TC 的描述是“自动配置 W3 Total Cache 以符合 Yoast SEO 要求”，但 2.10.3 实际代码只有两类行为。

激活时修改 W3TC 配置：

```text
pgcache.prime.enabled = true
pgcache.prime.sitemap = /sitemap_index.xml
```

即启用 Page Cache cache preload，并使用 Yoast sitemap index作为预加载来源。

运行时只有在 W3TC 自己的 CDN 模块启用时才注册：

```text
wpseo_xml_sitemap_img_src
```

把 sitemap 图片 URL 改写为 W3TC CDN URL。

### 它不做什么

代码中未发现该 Extension：

- 注册 `save_post`；
- 注册 `w3tc_flush_post`；
- 清理文章、首页、term 或 feed Page Cache；
- flush Object Cache group；
- 删除 Redis key；
- 修改 Yoast canonical；
- 解决 sitemap host/language选择；
- 包含 Polylang、www/en/admin 或 multi-domain 特殊逻辑。

因此它主要是 **Page Cache预加载配置**，外加可选的 sitemap图片 CDN URL改写；不是内容更新后的缓存失效 Extension。

### 对缓存失效的影响

它不会新增 purge。启用 preload 可能在缓存失效后根据 sitemap重新生成页面，但不能补上“错误 Host 没有被失效”的缺口。

风险：

- 增加 Origin预热请求和资源消耗；
- 当前 sitemap按 www/en Host分离，单一 `/sitemap_index.xml` 的预热覆盖范围需要另行确认；
- 容易把“重新生成缓存”误当成“正确失效所有语言 Host”。

### 是否值得作为第一个实际测试方案

**暂不推荐。**

最核心理由：它没有 cache invalidation hook，不能解决目前关注的 admin → www/en 失效传播；它只改变 Page Cache preload。

## Polylang + Yoast SEO 官方兼容

Polylang 3.8.6 自带 `PLL_WPSEO` integration。当检测到 `WPSEO_VERSION` 时自动在 `pll_init` 加载；不依赖 W3TC Yoast Extension。

### permalink

Polylang官方 post/term link filters按对象自身语言生成 URL：

- zh post/term → www；
- en post/term → en。

并强制 Yoast启用 dynamic permalinks：

```text
wpseo_dynamic_permalinks_enabled → true
```

### sitemap

在多域名模式下，Polylang：

- 给 Yoast sitemap post查询加入语言 JOIN/WHERE；
- 按当前语言限制 sitemap内容；
- 修正 sitemap `home_url` 到当前语言域名；
- 对 inactive language停用 sitemap；
- 禁用 Yoast XML sitemap transient cache：

```text
wpseo_enable_xml_sitemap_transient_caching → false
```

源码注释明确说明原因：否则 Yoast只保留一个 domain。

该机制与已观察到 www/en sitemap正确分域一致。

### canonical 与 SEO metadata

Polylang：

- 将 Yoast文件加入 `pll_home_url_white_list`，允许 frontend `home_url()` 切换语言域名；
- 过滤 `wpseo_canonical`，修正 front page trailing slash；
- 过滤 Yoast frontend presentation，使用可翻译的首页/CPT archive SEO options；
- 增加多语言 Open Graph locale presenter；
- 翻译 Yoast title/social options。

结论：当前 Yoast + Polylang 已具备明确的多域名 URL上下文能力，能够按 zh/www、en/en 生成 permalink、sitemap URL，并在 frontend语言上下文中生成 canonical。它解决的是 URL与 SEO输出，不是 W3TC跨 Host缓存失效。

## Polylang 官方缓存相关机制

### 当前多域名状态

只读结果：

| 项目 | 当前值 |
| --- | --- |
| `force_lang` | `3` |
| links model | `PLL_Links_Domain` |
| zh home | `https://www.shuijingwanwq.com/` |
| en home | `https://en.shuijingwanwq.com/` |
| `hide_default` | `false` |
| browser language detection | `false` |

多域名映射当前正确。

### `PLL_FILTER_HOME_URL`

- 当前：默认 `true`
- 用途：frontend语言确定后，允许 Polylang过滤适当调用来源的 `home_url()`。
- 影响：www/en frontend URL、Yoast等白名单调用。
- 限制：admin 使用基础 links filters；它不能自动让任意 admin保存流程获得 post-specific语言首页。

当前无需调整。

### `PLL_CACHE_HOME_URL`

- 当前：默认 `true`
- 用途：允许语言对象复用已经计算的 `home_url`；设为 false时通过 `pll_language_home_url` 动态计算。
- 当前 zh/en cached home值正确。
- 它不负责 W3TC Page Cache purge，也不负责跨 Host Redis失效。

没有证据支持优先关闭。

### `PLL_CACHE_LANGUAGES`

- 当前：默认 `true`
- 用途：将语言列表持久化到数据库 transient，并在 Polylang request-local cache中复用。
- 设为 false会绕过语言列表 persistent cache并动态从 taxonomy重建。
- 当前语言列表与 home映射正确。

没有证据支持优先关闭；关闭会增加计算成本。

### `pll_is_cache_active`

- 当前：`true`，因为 `WP_CACHE=true`。
- 用途：加载 Polylang通用 cache compatibility。
- 该 compatibility在 `clean_post_cache` 时按 post语言修正 translated CPT archive link，并处理部分页面缓存插件兼容。
- 它不是 W3TC Object Cache全局失效器。

### Polylang对象关系缓存

Polylang 3.8 对翻译对象使用 WordPress cache groups 与 `last_changed`/salted cache机制，并在语言关系改变时调用 `wp_cache_set_last_changed()`。

该机制是应优先保留的官方实现；在 W3TC按 HTTP_HOST划分物理 item namespace时，其跨 Host传播仍属于需要验证的底层边界，不能通过随意关闭 Polylang语言缓存替代。

## 其他可能相关的 W3TC Extensions

| Extension | 当前状态 | 相关性判断 |
| --- | --- | --- |
| `wordpress-seo` | inactive | 只做 Yoast sitemap驱动的 Page Cache preload及可选图片 CDN改写；不解决 invalidation |
| `wpml` | inactive | 针对 WPML，不适用于 Polylang |
| `fragmentcache` | inactive | 缓存页面片段；不会补齐 admin → www/en 的 post/home失效传播 |
| `alwayscached` | inactive | 持续生成/预热 Page Cache；不能替代正确 purge |
| `cloudflare` | inactive | 属于 Phase 2，本轮冻结 |

W3TC 2.10.3 没有独立的 Feed、Object Cache invalidation、Polylang或通用 multi-domain Extension可直接解决当前 Phase 1边界。Object Cache是 W3TC核心模块，不是可启用的 Extension。

## 现成方案优先级

### A. 可以直接使用的官方现成机制

#### A1. Polylang Domain Links + URL filters

- 解决：zh/www、en/en 的 permalink、term、home URL。
- 当前：已启用且映射正确。
- 风险：低，保持现状。
- 验证成本：低；Origin检查 permalink、canonical、hreflang、sitemap。
- 优先测试：是，作为 URL正确性的基线，而不是新增变更。

#### A2. Polylang + Yoast integration

- 解决：多域名 sitemap查询、sitemap host、Yoast dynamic permalink、canonical/SEO呈现。
- 当前：随两个 active插件自动加载。
- 风险：低，保持现状。
- 验证成本：低。
- 优先测试：是；当前 sitemap正确已经提供正向证据。

#### A3. WordPress Core精确对象失效与 W3TC标准 Page Cache purge

- 解决：单一请求 Host内的 post object和标准 Page Cache URL失效。
- 当前：已启用。
- 风险：低，保持现状。
- 验证成本：中，需要 Origin分层验证。
- 优先测试：是，但已知其多 Host边界不能只靠配置推断为完整。

### B. 可以通过启用现有 Extension 解决的机制

当前没有找到能解决 admin → www/en Page/Object Cache失效传播的 W3TC Extension。

W3TC Yoast SEO Extension只能提供 sitemap驱动的 Page Cache preload，不列为当前 invalidation方案。

### C. 可以通过少量配置解决的机制

当前没有证据支持立即修改配置：

- W3TC `purge.home`、`purge.post` 已开启；
- Polylang domain mode和 home URL filters已正确启用；
- Polylang语言/home persistent cache当前返回正确值；
- 开启更多 broad purge项不能自动选择 en语言首页；
- 关闭 Polylang缓存 constants不能解决 W3TC跨 Host namespace。

因此本轮不提出“先改一个设置试试”。

### D. 仍需要验证的现成方案

#### D1. 当前官方栈的 Origin URL/SEO回归矩阵

- 解决：确认不需要额外补丁的部分确实由 Polylang + Yoast官方机制覆盖。
- 当前：机制已启用。
- 风险：只读验证，极低。
- 验证成本：低。
- 值得优先测试：是。

检查 www/en 的：

- post permalink；
- canonical；
- hreflang；
- sitemap index和子 sitemap host；
-首页及归档内部链接。

#### D2. W3TC标准 purge + 已有兼容补丁的 Origin行为

- 解决：判断当前生产是否已经稳定覆盖已确认的最小多 Host边界。
- 当前：官方机制和既有 MU兼容补丁均已存在。
- 风险：若只做观察，低。
- 验证成本：中；需要一次可观测内容事件或安全测试环境。
- 验证结果：21022真实 admin Update已确认 en首页缓存被 purge，且共享
  `posts` group version从 `803` 推进到 `805`。

### E. 现有方案无法解决后才需要 MU Plugin 的缺口

源码基线已指出两个官方机制未提供的边界：

- admin保存英文 post时，W3TC原生首页 purge不包含 en首页；
- W3TC Redis item key按当前 Host分隔，而 Core单 key失效只发生于 admin namespace；官方 post流程不做跨 Host `posts` group flush。

生产中的 `swq-w3tc-polylang-purge.php` 定位为已有兼容补丁，不扩展、不重构。

当前已有补丁已经通过一次真实英文文章 Update覆盖这两个最小边界。只有后续
出现具体、可复现且官方机制与现有补丁无法处理的 Origin故障时，才重新判断
是否存在新的最小缺口。当前没有理由开发第二个新插件。

## 当前最值得优先测试的现成方案

**不启用 W3TC Yoast SEO Extension。**

最值得优先测试的是当前已经启用的官方组合：

```text
Polylang multi-domain URL model
+ Polylang built-in Yoast integration
+ W3TC standard purge settings
```

并将已有 MU Plugin仅视为现存兼容补丁，通过 Origin回归矩阵验证结果。

理由：

- 无配置变更；
- 无新维护组件；
- permalink、canonical、sitemap多域名能力已有代码和实际正向证据；
- 能快速把“官方已解决的 URL上下文”与“W3TC仍未解决的跨 Host invalidation”分开；
- 验证后才能决定是否保留、缩小或删除现有 MU补丁。

## W3TC Yoast SEO Extension 明确建议

**暂不推荐。**

它适合需要 sitemap驱动 Page Cache preload的站点，但当前目标是缓存失效正确性。启用它不会新增 save/update purge，反而增加预热流量和新的判断变量。

若以后独立评估 preload，应另做低风险、可回滚测试；不应把该测试当作 Phase 1首个修复方案。
