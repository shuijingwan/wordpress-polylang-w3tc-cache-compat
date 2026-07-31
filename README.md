# Polylang W3TC Cache Compatibility

一个小型 WordPress MU Plugin，用于补充 Polylang多域名模式下 W3 Total
Cache在文章更新时的缓存失效。

## 解决什么问题

当 WordPress后台使用独立 Host，而 Polylang为不同语言使用不同前台域名时，
W3 Total Cache可能存在两个缓存失效缺口：

1. 保存其他语言的普通文章时，原生 Page Cache清理可能遗漏该语言首页；
2. Redis Object Cache的物理对象按 Host区分，后台请求中的精确对象失效不能
   自然覆盖其他前台 Host中的已有 `posts`缓存。

本插件：

- 根据文章自身的 Polylang语言取得对应语言首页，并通过
  `w3tc_flush_url()`精确补充该首页的 Page Cache清理；
- 在 Object Cache支持 group flush时调用
  `wp_cache_flush_group( 'posts' )`，使共享 `posts` generation失效；
- 同一个 PHP请求内最多主动 flush一次 `posts` group。

域名映射完全来自 Polylang，不需要配置或硬编码语言域名。

## 适用环境

- WordPress 6.1或更高版本；
- PHP 7.4或更高版本；
- Polylang多域名语言模式；
- W3 Total Cache Page Cache；
- W3 Total Cache Object Cache及支持 `flush_group`的持久化对象缓存。

如果 Polylang、W3 Total Cache或 Object Cache group flush能力不存在，对应
逻辑会安全退出，不产生 Fatal Error。Page Cache与 Object Cache补偿彼此独立。

## 安装

将：

```text
src/polylang-w3tc-cache-compat.php
```

复制到：

```text
wp-content/mu-plugins/polylang-w3tc-cache-compat.php
```

WordPress会将其作为 MU Plugin自动加载，无需在后台启用。

## v0.1范围

v0.1只处理 WordPress内置 `post`：

- 使用 `pll_get_post_language()`读取文章语言；
- 使用 `pll_home_url()`取得该语言首页；
- 使用 `w3tc_flush_url()`清理该首页 Page Cache；
- 使用 `wp_cache_flush_group( 'posts' )`有限失效 `posts` Object Cache组。

## 不处理什么

v0.1不处理：

- options；
- term、category、tag、taxonomy；
- menu；
- `wp_template`、`wp_template_part`；
- Global Styles、Theme JSON；
- CDN、Cloudflare、EdgeOne；
- 全 Redis、全 Object Cache或无条件全站 Page Cache清理；
- 所有 WordPress对象的通用缓存失效。

## 生产验证

v0.1公共实现已在 WordPress、Polylang多域名、W3 Total Cache 2.10.3、
Redis和独立后台 Host的真实生产环境中验证：

- 更新英文文章后，英文首页 Page Cache文件在首次 Origin请求前已经被删除，
  随后由 Origin请求重新生成；
- 公共 v0.1部署后的一次真实后台 Update中，共享 `posts` group version从
  `813`推进到 `815`。

这证明语言首页 Page Cache补偿和跨 Host `posts` Object Cache补偿均实际生效。
该结论只覆盖普通文章发布/更新场景，不代表所有缓存对象均已验证。

## 许可证

MIT。详见 [LICENSE](LICENSE)。
