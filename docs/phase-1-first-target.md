# Phase 1 第一目标

## 当前结论

当前第一个调查目标是：

**建立 W3TC 原生 `save_post` 缓存失效在 Polylang 单 Host `/en/` 与多域名 + admin 架构之间的行为差异基线。**

## 问题名称

W3TC 原生 `save_post` 的 Page Cache URL Host 选择与 Object Cache 当前-Host 失效边界。

## 现象

旧架构中 zh/en 共用 www Host；新架构中内容从 admin Host 保存，但前台缓存位于 www/en。需要先确认 W3TC 官方流程能否把失效作用到正确前台 Host，再判断 MU Plugin 是否需要补偿。

## 已有证据

- 生产插件明确 flush `posts` 和 `options` group。
- 生产插件对普通 post 追加 Polylang 语言首页 Page Cache purge。
- W3TC 2.10.3 的 `w3tc_flush_post` 确实覆盖普通发布/更新路径。
- 21018/21022 的当前 Origin、Page Cache 与 Polylang 输出正确。
- 静态源码基线已确认：
  - post permalink 经过 Polylang 按 post 自身语言过滤；
  - admin 请求中的 W3TC 首页和 blog feed 使用默认 WordPress home；
  - W3TC Redis item key 包含当前 `HTTP_HOST`；
  - WordPress Core 的单 key 删除和 `posts:last_changed` 更新只进入当前 Host namespace；
  - W3TC 官方 `w3tc_flush_post` 没有 Object Cache `posts` group flush。

详细证据见 `w3tc-native-save-post-flow.md`。本阶段只确认原生边界，不设计修复。

## 影响对象

post permalink、语言首页、blog feed、post object、post query cache。

## 影响 Host

admin 是写入入口；www/en 是前台缓存目标。admin 不应被当作第三个前台站点。

## 当前行为

当前配置下，W3TC 官方静态推演：

- 保存中文 21018：官方 Page Cache URL 都位于 www。
- 保存英文 21022：英文 permalink 位于 en，但首页与 blog feed 位于 www；en 首页不在原生队列。
- Core Object Cache 清理在 admin Host namespace 中执行；W3TC 官方不补充跨 Host `posts` group flush。

## 预期行为

从 admin 保存内容后，官方失效目标应覆盖对象实际所属的 www/en 前台 URL；共享 WordPress 对象发生变化时，前台 Host 不应继续读取旧对象或旧 query cache。

## 可能涉及层级

W3TC Page Cache URL generation、Polylang URL filters、WordPress Core Object Cache invalidation、W3TC Redis physical namespace。

## 已经排除的层

- 21018/21022 案例中，Database、Polylang、当前 W3TC Page Cache 和 Origin HTTP 已确认正常。
- 该案例的异常属于 Phase 2 CDN HTTP，不能用于选择 Phase 1 目标。
- `admin.shuijingwanwq.com` 不会作为当前官方 post save 的前台 Page Cache目标；问题不是需要维护第三套前台页面。

## 后续生产验证结果

静态机制差异建立后，用户通过 `admin.shuijingwanwq.com` 对英文文章
21022执行了一次真实 Update：

- Update后、首次 Origin GET前，en首页 Page Cache文件不存在；
- 第一次本机 Origin GET返回 200、首篇为 21022，并以新 mtime重新生成缓存；
- 共享 `posts` group version从 `803` 推进到 `805`。

因此当前生产 MU Plugin对本目标中的两个已确认原生缺口均已完成运行时验证。
完整证据见 `phase-1-post-save-runtime-validation.md`。

## 当前完成边界

文章发布/更新场景下的核心 multi-Host Origin cache invalidation已完成首次
生产验证。无需修改或重写当前 MU Plugin。该结论不覆盖 term、menu、
options、Block Theme对象、Global Styles或 Theme JSON，也不表示 Phase 1
全部完成。
