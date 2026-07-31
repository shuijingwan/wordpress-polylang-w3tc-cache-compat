# Phase 1 已知 Origin 缓存故障

## 证据标准

只有能在同一问题中区分并对照以下至少相关层级的资料，才记录为真实 Origin 故障：

- Database；
- WordPress PHP / WP-CLI；
- Redis / Object Cache；
- W3TC Page Cache 文件；
- Origin HTTP / HTML。

生产源码注释、能力缺口、理论风险或文章标题不能单独证明故障。

## 已有真实故障证据

**当前本项目资料中，没有一项具备足够分层证据、可列为已确认的 Phase 1 Origin 缓存故障。**

这不表示生产环境从未发生 Origin 问题，只表示当前仓库尚未保存足以复核的证据。

这与“已经确认 W3TC原生 multi-Host失效边界”并不矛盾。真实 admin英文
文章 Update已经确认原生机制存在的两个缺口由当前 MU Plugin实际补偿，最终
Origin状态符合预期；因此它们是**已确认且已补偿的原生缺口**，不是当前仍未
解决的 Origin故障。

## 已确认且已补偿的原生缺口

| 原生缺口 | 现有补偿 | 生产运行时证据 | 当前状态 |
| --- | --- | --- | --- |
| admin保存英文 `post` 时，W3TC 2.10.3原生 Page Cache队列缺少 en homepage | 按 `pll_get_post_language()` → `pll_home_url()` 取得 en首页，并调用 `w3tc_flush_url()` | 更新 21022后、首次 Origin GET前，en首页缓存文件不存在；GET后以新 mtime重新生成 | 原生缺口已确认；现有 MU Plugin已补偿；生产运行时已验证 |
| Core精确 Object Cache失效只作用于 admin Host物理 namespace，不能自然失效 www/en已有副本 | `wp_cache_flush_group( 'posts' )` 推进共享且不含 Host的 group version | 同一次真实 Update中精确 version key从 `803` 推进到 `805` | 原生缺口已确认；现有 MU Plugin已补偿；生产运行时已验证 |

## 历史背景与待验证线索

下列内容不计入“已知 Origin 故障”：

| 线索 | 现有资料 | 分类 | 证据结论 |
| --- | --- | --- | --- |
| W3TC Object Cache flush 后，其他 Host 的 option 可能仍旧 | 生产 MU Plugin 注释提及 `wpcode_snippets`，代码因此 flush `options` group | 尚未确认根因 | 尚未验证：缺少 option 数据库值与三 Host `get_option()` 对照 |
| `wp_template` / `wp_template_part` 陈旧 | 当前插件无专门处理，21018 标题涉及 Object Cache | 尚未确认根因 | 尚未验证：标题和能力缺口不是分层证据 |
| term/category/tag/menu/Global Styles/Theme JSON | 当前插件无专门处理 | 尚未确认根因 | 尚未验证：只有理论覆盖缺口 |
| 英文页面 `<html lang="zh-CN">` | 项目背景曾提及，当前用户没有对应故障印象，当前调查未确认 | 尚未确认根因 | 尚未验证；仅保留为最终验证项 |

当前没有足够证据把上述剩余线索分类为“已解决”“临时恢复”或“仍需解决”。

## 明确排除：21018 / 21022

21018 / 21022 不属于 Phase 1 Origin 故障：

- Database 正确；
- Polylang 关系正确；
- 当前 W3TC Page Cache 文件正确；
- Origin www/en 首页分别返回 21018/21022；
- 公网 EdgeOne/Cloudflare 曾继续返回旧 HIT。

它是 Phase 2 CDN Cache Invalidation 的真实测试案例，证据保留在 `post-21018-runtime-cache-investigation.md`，当前暂停处理。
