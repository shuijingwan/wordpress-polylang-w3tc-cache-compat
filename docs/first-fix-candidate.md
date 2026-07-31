# Phase 2 修复候选：发布后可靠失效两个 CDN 的语言首页

## 阶段归类

本文保留 21018 / 21022 调查得到的 CDN 证据，但候选现已归入：

**Phase 2：CDN Cache Invalidation**

它不是当前 Phase 1 的第一修复目标。Phase 1 只处理 Origin Cache Consistency；当前暂停 Cloudflare、EdgeOne、CDN API、TTL、凭据和 purge 实现调查。

## 结论

Phase 2 的候选方向是：**为已发布文章对应的中英文首页建立可验证的外部 CDN purge 路径**。

该候选仅为后续阶段的分析记录，当前不实施修改，也不作为 Phase 1 排序依据。

## 1. 已确认现象

- 数据库中 21018（zh）和 21022（en）都已发布。
- 源站 www 首页首篇为 21018，源站 en 首页首篇为 21022。
- 当前 W3TC Page Cache 首页文件也分别包含 21018 和 21022。
- 公网 www 首页由 EdgeOne 返回 `HIT`，首篇仍是 20541（7 月 28 日）。
- 公网 en 首页由 Cloudflare 返回 `HIT`，`Age: 163252`，首篇仍是 20643（7 月 29 日）。
- 两个公网 HTML 均不包含 21018 或 21022。

## 2. 根因证据与边界

已确认的故障点是：**取证时两个外部 CDN 都在源站已正确的情况下继续返回旧首页缓存。**

支持证据：

- Origin 与 CDN 对同一 URL 返回不同文章列表。
- 两个 CDN 响应均明确标记为 HIT。
- 当前 W3TC 配置中 `cdn.enabled=false`、`extensions.active=[]`。
- 当前生产 MU Plugin 只调用 `w3tc_flush_url()` 和 Object Cache group flush，没有 EdgeOne/Cloudflare purge API。

尚未确认：

- CDN 控制台的 cache rule、TTL 与 stale-serving 规则。
- 发布 21018/21022 时是否有 W3TC 之外的系统尝试发送 CDN purge。
- EdgeOne/Cloudflare 的 purge audit log。
- 两家 CDN 应使用 URL purge、cache-tag purge，还是通过缩短首页 TTL解决。

因此本文能确认 CDN 是最早出现异常的层级，但还不能把“未发送 purge”作为已经完整证明的历史事件。

## 3. 最小修改位置

候选修改位置是发布清理流程中、已经确定首页 URL 后的外部 CDN 失效边界。可复用现有 `w3tc_flush_post` 流程提供的 post ID 与 Polylang 首页 URL，但 CDN purge 必须与 W3TC本地 `w3tc_flush_url()` 分开处理，并针对：

- `https://www.shuijingwanwq.com/` → EdgeOne
- `https://en.shuijingwanwq.com/` → Cloudflare

当前不应直接修改生产 MU Plugin。实施前需要先确认 CDN 配置、凭据管理方式、API 权限和现有 purge 责任方，才能决定修改应位于 WordPress adapter、部署系统还是 CDN 规则。

## 4. 可能影响范围

- 每次普通文章发布/更新时的外部 API 调用次数。
- EdgeOne 与 Cloudflare API 配额、限流和失败重试。
- 首页缓存命中率与源站请求量。
- 中英文翻译在短时间内分开发布时的重复 purge。
- API 凭据安全、日志脱敏和失败告警。
- 如果错误地扩大 URL 范围，可能造成不必要的全站缓存失效。

## 5. 验证方法

在测试版本和可审计环境中：

1. 记录发布前 www/en 首页的 CDN cache 状态和首篇 ID。
2. 发布一组中英文测试文章，并记录 WordPress hook、目标语言首页 URL 和 CDN purge API 响应。
3. 不加随机 query string 请求两个公网首页。
4. 确认 www 首篇变为中文测试文章，en 首篇变为英文测试文章。
5. 同时确认两个源站首页与 W3TC Page Cache 文件内容一致。
6. 检查 CDN purge audit log，确认 purge URL、时间和结果。
7. 重复更新同一文章，验证去重、限流、失败重试和错误日志。

## 6. 回滚方法

- 禁用新增的 CDN purge 调用入口，不改动现有 W3TC Page/Object Cache 行为。
- 删除或撤销新增 CDN API 凭据权限。
- 恢复修改前的 CDN cache rule。
- 若验证期间降低了首页 TTL，恢复原 TTL。
- 回滚后分别检查源站和公网首页，确保只撤销外部 CDN 失效能力。

## 7. 是否已有足够证据实施

**当前不实施修改，需要补充以下证据：**

1. EdgeOne 和 Cloudflare 当前首页 cache rule、TTL、stale 行为。
2. 两家 CDN 对 21018/21022 发布时间附近的 purge/audit log。
3. 是否已有 W3TC 之外的 CDN purge 责任方。
4. 每家 CDN 最小权限的 URL purge API 与安全凭据存放方案。

现有证据足以把“外部 CDN 首页失效”列为 Phase 2 候选，但不足以安全选择具体实现位置和 API 方案。在 Phase 1 完成前，本项冻结。
