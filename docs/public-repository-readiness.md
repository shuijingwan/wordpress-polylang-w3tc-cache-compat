# 公共 GitHub 仓库发布前审查

## 1. 仓库定位

公共仓库应定位为：

> Polylang多域名模式下，补充 W3 Total Cache在内容更新时的缓存失效兼容。

项目主线应是一个小型、可复用的 WordPress MU Plugin，而不是
shuijingwanwq.com网站缓存调查仓库。现有调查文档继续作为设计依据、真实故障
证据和生产验证记录，但不能让公共实现依赖其中的域名、路径、文章 ID、Redis
key或时间戳。

当前最准确的完成程度是：

> 文章发布/更新场景下的核心 multi-Host Origin cache invalidation已完成首次
> 生产验证。

这不表示 Phase 1全部完成，也不表示所有 WordPress对象或缓存问题已经解决。

## 2. 已验证核心功能

W3 Total Cache 2.10.3已经确认存在两个 multi-Host原生缺口：

1. 从独立 admin Host保存英文 `post` 时，原生 Page Cache队列缺少 en
   homepage；
2. WordPress Core精确 Object Cache失效只作用于当前 admin Host对应的
   W3TC Redis物理 namespace，不能自然失效 www/en中的已有副本。

当前生产 MU Plugin已经通过静态分析和一次真实 admin英文文章 Update验证以下
补偿：

1. 使用 `pll_get_post_language()`取得文章语言，再用 `pll_home_url()`取得
   该语言首页，通过 `w3tc_flush_url()`补充 Page Cache purge；
2. 在 W3TC post flush事件中调用 `wp_cache_flush_group( 'posts' )`，推进
   W3TC Redis中不含 Host的共享 `posts` group version。

真实验证中，en首页 Page Cache在 Update后、首次 Origin GET前不存在，随后由
Origin GET重新生成；共享 version从 `803`推进到 `805`。这证明至少发生了有效
group flush。当前不继续调查为什么一次 Update观察到增量为2。

公共 v0.1随后替换生产参考版本并通过一次独立的真实后台 Update验证：

- 共享 `posts` group version从 `813`推进到 `815`；
- Update后、首次 Origin GET前，en首页 Page Cache文件不存在；
- 首次 en Origin GET返回 HTTP 200、首篇为测试英文文章，并重新生成缓存；
- www Origin返回 HTTP 200，admin返回正常登录重定向。

因此，当前公共 v0.1本身已经完成语言首页 Page Cache和跨 Host `posts`
Object Cache两项生产运行时验证。

## 3. 尚未验证功能

以下对象没有足够的生产故障证据或对应运行时验证，不进入 v0.1功能范围：

- `options`；
- term、category、tag和 taxonomy archive；
- menu；
- `wp_template`；
- `wp_template_part`；
- Global Styles；
- Theme JSON；
- Polylang翻译关系的关联对象遍历；
- CDN purge。

这些是“尚未验证”或“当前未覆盖”的能力，不应描述为已知待修 Bug。
生产参考版本中的 `w3tc_flush_after_objectcache` → `options` group flush确实
存在，但其生产必要性仍未确认。

## 4. 敏感信息检查

已从准备公开 GitHub的角度再次检查所有已提交文件：

- `README.md`；
- `.gitignore`；
- `docs/`；
- `production-reference/`；
- `src/`。

检查范围包括 Secret、Token、Password、Cookie、Nonce、Redis密码、API
credential、私钥、Cloudflare/Tencent凭据、个人信息和临时调试凭据。

**未发现阻止公开的敏感凭据。**

文档中出现 “Redis password” 是说明取证没有输出密码，不包含密码值。没有发现
私钥块、认证 header、Cookie值、Nonce值或可用 API token。

仓库包含一些生产身份和基础设施信息。这些不是认证秘密，但公开前应由仓库所有者
明确接受其公开性：

- 真实公网域名和后台 Host；
- 生产 WordPress绝对路径；
- localhost Origin/Redis地址；
- 生产文章标题、ID和时间戳；
- W3TC内部 Redis key与生产文件 SHA256。

已提交文件内容中没有发现用户姓名、邮箱、家庭地址或其他直接个人身份信息。
Git历史另含 author/committer名称和邮箱；它不是 Secret，但 GitHub会公开显示。
仓库所有者必须在发布前确认愿意公开该身份信息，否则应在 push前改写本地提交
metadata。本文件不记录具体邮箱值。

## 5. 生产专属信息清单

### A. 调查证据中可以保留的真实案例

以下信息可以留在 `docs/`，前提是仓库所有者接受公开真实生产案例：

| 类型 | 当前内容 | 保留理由 |
| --- | --- | --- |
| 真实域名 | `www.shuijingwanwq.com`、`en.shuijingwanwq.com`、`admin.shuijingwanwq.com` | 证明 multi-domain +独立后台 Host拓扑和 URL purge目标 |
| 生产路径 | `/data/wwwroot/www.shuijingwanwq.com`及其 cache/plugin子路径 | 记录文件级 Page Cache取证位置 |
| 文章案例 | `21018`、`21022`及相关公开文章 URL/标题 | 连接数据库、Polylang、Page Cache和 Origin证据 |
| Redis运行时证据 | `w3tc_2889287881_0_object_posts_key_version`、`803 → 805` | 证明共享 group version实际推进 |
| 文件完整性 | 生产插件 SHA256 `7c8533...d5d7ce`及 Page Cache SHA256 | 证明参考代码和前后文件状态 |
| 时间证据 | `2026-07-31`的 post/cache时间戳 | 建立 Update、purge和重新生成的事件顺序 |
| 本机服务地址 | `127.0.0.1`、`127.0.0.1:6379` | 说明 Origin绕过 CDN以及 Redis为本机服务 |
| CDN供应商名称 | EdgeOne、Cloudflare | 解释 Phase 2边界；本阶段不处理其配置 |

这些内容只能是案例证据，不能成为公共插件的默认配置或控制流条件。

### B. 公共插件实现中绝不能写死

未来公共代码不得硬编码或依赖：

- `shuijingwanwq.com`及其任何子域名；
- `zh → www`、`en → en`的站点映射；
- `/data/wwwroot/...`生产路径；
- 文章 ID `21018`、`21022`或其他生产对象 ID；
- Redis服务器、数据库编号、instance ID、完整 version key或版本值；
- 生产文件 SHA256、mtime、cache文件路径；
- EdgeOne、Cloudflare或任何 CDN凭据/API；
- admin Host名称或 `HTTP_HOST`伪造；
- 当前站点的文章标题、主题、菜单或 option名称。

## 6. `production-reference` 定位

文件：

`production-reference/swq-w3tc-polylang-purge.php`

审查结果：

| 问题 | 结论 |
| --- | --- |
| 是否包含凭据 | 否 |
| 是否硬编码生产域名 | 运行时代码没有；注释出现 `admin.shuijingwanwq.com` |
| 是否包含生产路径 | 否 |
| 是否包含站点专属逻辑 | 是；注释描述三 Host拓扑和 WPCode案例，并包含尚未验证必要性的 `options` group补偿 |
| 是否可直接作为 v0.1公共插件 | 否 |

该文件应继续保持不修改的“生产环境原始参考版本”，用于完整性核对和证据追溯。
公共实现未来应从 `src/`单独产生，只提取已经验证且属于 v0.1范围的行为。

它不是因为含有凭据而不能公开，而是因为其范围和说明混合了生产专属假设，
尤其包含不应进入 v0.1的 `options`逻辑。

## 7. v0.1 范围

v0.1目标：

> 补偿 W3 Total Cache在 Polylang多域名模式下，从独立后台 Host保存文章时的
> 语言首页 Page Cache和跨 Host `posts` Object Cache失效问题。

候选公共功能严格限制为：

1. 在 W3TC post flush事件中接收并校验 post ID；
2. 仅处理 WordPress内置 `post`，与当前已验证边界一致；
3. 使用 `pll_get_post_language( $post_id, 'slug' )`确定对象语言；
4. 使用 `pll_home_url( $language )`取得该语言 frontend homepage；
5. 使用 `w3tc_flush_url()`补充该完整 URL的 Page Cache purge；
6. 在 Object Cache API存在且声明支持 `flush_group`时，对 `posts` group执行
   一次有限范围失效；
7. 对同一 PHP请求中的重复 post flush进行去重；
8. 依赖缺失、对象无效或返回值无效时安全退出。

公共实现不需要提供 www/en域名配置。Polylang应是
“语言 → frontend URL”的唯一来源；插件不应读取、切换或伪造当前
`HTTP_HOST`来推导语言首页。

## 8. 非目标

v0.1明确不负责：

- EdgeOne、Cloudflare或其他 CDN purge；
- 替代 W3 Total Cache或 Polylang；
- 清空整个 Redis或整个 Object Cache；
- 无条件全站 Page Cache flush；
- `options` group补偿；
- term、category、tag、taxonomy或 menu失效；
- `wp_template`、`wp_template_part`、Global Styles或 Theme JSON；
- 所有 post type的通用缓存失效；
- 翻译对象、关联 URL和 SEO metadata的万能同步；
- W3TC内部机制的全面抽象。

## 9. 公共化前必须做的修改

v0.1最小公共实现已经从 `production-reference`单独提取到
`src/polylang-w3tc-cache-compat.php`，没有 `options`逻辑或生产站点硬编码。
README已经改为安装、要求、范围和验证说明，并加入 MIT许可证。

首次 push前只需确认：

1. 当前 Git历史中的 author/committer名称和邮箱可以公开；若不可公开，先改写
   本地提交 metadata；
2. 仓库所有者接受 `docs/`中真实生产案例信息公开；
3. `production-reference/`继续明确作为不可安装的原始证据，而不是公共入口。

### 前置条件设计

推荐的 v0.1支持基线：

| 前置条件 | 建议 |
| --- | --- |
| WordPress | 最低 WordPress 6.1，以完整依赖 `wp_cache_flush_group()`和 `wp_cache_supports()`；若决定支持更旧版本，只能安全跳过 Object Cache补偿 |
| PHP | 建议最低 PHP 7.4；发布前按实际 CI/目标依赖版本最终确认 |
| Polylang | 必须 active，并存在 `pll_get_post_language()`与 `pll_home_url()` |
| W3 Total Cache | 必须 active，并存在 `w3tc_flush_url()`及 `w3tc_flush_post`事件 |
| Object Cache group flush | `wp_cache_flush_group()`、`wp_cache_supports()`均存在，且 `wp_cache_supports( 'flush_group' )`为真时才执行 |

这里的 WordPress/PHP版本是公共发布的建议支持基线，不是已完成的跨版本兼容
承诺。v0.1实现必须逐项使用 `function_exists()`和有效返回值检查；Polylang、
W3TC或 group flush能力缺失时安全退出，不能产生 Fatal Error。Page Cache与
Object Cache分支应独立降级，避免缺少 group flush时阻止语言首页 purge。

## 10. 推荐目录结构

最简单、低维护的开发结构：

```text
README.md
LICENSE
src/
  polylang-w3tc-cache-compat.php
docs/
  public-repository-readiness.md
  ...
production-reference/
  swq-w3tc-polylang-purge.php
```

`src/polylang-w3tc-cache-compat.php`作为唯一公共实现源文件。发布说明要求用户把
该文件直接复制到 `wp-content/mu-plugins/`根目录；WordPress不会自动加载
`mu-plugins`下任意子目录中的 PHP文件。

若未来希望用户直接 clone整个仓库到一个子目录，才增加一个位于
`wp-content/mu-plugins/`根目录的极小 loader或提供构建包。本项目 v0.1不需要
为此引入构建系统、Composer或复杂目录分层。

`production-reference/`保持只读证据身份，文件名和 README说明必须避免用户
误装。公共实现不得 include或 require该参考文件。

## 11. 许可证建议

项目采用 **MIT License**。

理由：

- 许可证简短清晰；
- 允许使用、修改、再分发和商业使用；
- 适合作为小型独立兼容 MU Plugin的公共许可证。

仓库现已加入标准 MIT正文的 `LICENSE`，插件头声明 `MIT`。

## 12. 当前发布判断

### 敏感信息结论

**未发现阻止公开的敏感凭据。**

真实域名、生产路径、文章 ID、Redis key、SHA256和时间戳属于已识别的生产案例
信息，不是凭据；可以作为 `docs/`证据保留，但需要仓库所有者明确接受公开。

### GitHub公共仓库就绪度

**从代码、许可证、README和敏感信息检查角度，v0.1已具备创建 GitHub公共
仓库的最小条件。**

创建前仍需由仓库所有者确认 Git author/committer身份信息和 `docs/`真实生产
案例可以公开。这是公开范围确认，不是代码或凭据阻塞项。
