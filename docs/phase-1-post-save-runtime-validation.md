# Phase 1 post-save runtime validation

## Test object

Baseline collected at `2026-07-31T12:37:36+08:00`.

| Field | Value |
| --- | --- |
| ID | `21022` |
| post_status | `publish` |
| post_type | `post` |
| post_date | `2026-07-31 11:09:12` |
| post_modified | `2026-07-31 11:12:50` |
| post_title | `WordPress Three-Domain Architecture Pitfalls: Adsterra Archive Ads Fail to Work, Traced to W3TC Object Cache` |
| Polylang language | `en` |
| Polylang lookup | `pll_get_post_language( 21022, 'slug' )` |
| English homepage | `https://en.shuijingwanwq.com/` |
| Homepage lookup | `pll_home_url( 'en' )` |
| Frontend post URL | `https://en.shuijingwanwq.com/2026/07/31/21022/` |
| Admin Host | `https://admin.shuijingwanwq.com` |

## Baseline

### Production MU Plugin

| Field | Value |
| --- | --- |
| Path | `/data/wwwroot/www.shuijingwanwq.com/wp-content/mu-plugins/swq-w3tc-polylang-purge.php` |
| Expected SHA256 | `7c8533b921e2d278942f1fd191d4d32d70592d44a4e86f58ee750097f7d5d7ce` |
| Observed SHA256 | `7c8533b921e2d278942f1fd191d4d32d70592d44a4e86f58ee750097f7d5d7ce` |
| Result | Match |

### English homepage Page Cache before Origin GET

| Field | Value |
| --- | --- |
| Full path | `/data/wwwroot/www.shuijingwanwq.com/wp-content/cache/page_enhanced/en.shuijingwanwq.com/_index_slash_ssl.html` |
| Exists | Yes |
| mtime | `2026-07-31 11:35:16.112457410 +0800` |
| Size | `282945` bytes |
| SHA256 | `4e852f43648bbbe9eda02cefdb9f29dad44fb59845c8c93b41560c0ae9e60cff` |
| Contains `/21022/` | Yes |
| First post | `21022` |

### English Origin GET

The request used the server-local Origin route:

```text
curl --resolve en.shuijingwanwq.com:443:127.0.0.1 https://en.shuijingwanwq.com/
```

It did not access the public Cloudflare route.

| Field | Value |
| --- | --- |
| HTTP status | `200` |
| Contains `/21022/` | Yes |
| First post | `21022` |

### Final pre-save Page Cache baseline after Origin GET

| Field | Value |
| --- | --- |
| mtime | `2026-07-31 11:35:16.112457410 +0800` |
| Size | `282945` bytes |
| SHA256 | `4e852f43648bbbe9eda02cefdb9f29dad44fb59845c8c93b41560c0ae9e60cff` |
| Changed by Origin GET | No; mtime, size, and SHA256 all remained identical |

The post-GET values above are the final pre-save Page Cache baseline.

### Object Cache `posts` group version before save

The value was read with one exact Redis accessor `GET` through a W3TC
`Cache_Redis` instance. No Redis password was printed, and no `KEYS`, `SCAN`,
`MONITOR`, `SET`, `DEL`, or flush command was used.

| Field | Value |
| --- | --- |
| Engine | `W3TC\Cache_Redis` |
| Exact version key | `w3tc_2889287881_0_object_posts_key_version` |
| Key structure | `w3tc_{instance_id}_{blog_id}_{module}_{group}_key_version` |
| Contains HTTP Host | No |
| `posts` group version before | `803` |

W3TC constructs this version key without a Host component, while individual
Object Cache item keys contain Host. The implementation therefore makes
www/en/admin use the same `posts` group version. The current runtime value
`803` was read directly once from that shared key.

Direct per-Host observation was not forced: WP-CLI initialized its Core
`WP_Object_Cache` fallback in this execution context, so it could not safely
represent three real frontend/admin Object Cache request contexts without
altering or fabricating `HTTP_HOST`. The shared relationship is confirmed by
the current W3TC key construction and the exact runtime key/value; the
save-triggered version transition remains for the post-save validation.

### Save state

**No Update or other save operation has been performed.**

Post `21022` was not modified. No Page Cache, Object Cache, Redis, database,
W3TC, Polylang, MU Plugin, Nginx, or CDN state was intentionally changed.
The only HTTP request was the specified server-local Origin GET, and it did
not change the English homepage Page Cache file.

## Post-save evidence

The user performed one real Update for post `21022` through
`https://admin.shuijingwanwq.com`. All observations below were collected
after that Update. No additional article update or cache flush was performed.

### Updated test object

| Field | Post-save value |
| --- | --- |
| ID | `21022` |
| post_status | `publish` |
| post_type | `post` |
| post_modified | `2026-07-31 12:40:18` |
| post_modified_gmt | `2026-07-31 04:40:18` |
| post_title | `WordPress Three-Domain Architecture Pitfalls: Adsterra Archive Ads Fail to Work, Traced to W3TC Object Cache` |

The new `post_modified` confirms that the Update was written to the database.

### Object Cache `posts` group version

The same exact Redis key was read with one accessor `GET`. No Redis scan,
write, delete, or flush command was used.

| Field | Value |
| --- | --- |
| Exact key | `w3tc_2889287881_0_object_posts_key_version` |
| Before Update | `803` |
| After Update | `805` |
| Transition | `803 → 805` |
| Compensation status | **运行时已确认** |

The version advanced beyond `803` during the real admin Update. This confirms
that the production flow actually executed the shared `posts` group
invalidation. An increment larger than one does not weaken that conclusion;
this validation did not perform a compensating or manual flush.

### English homepage Page Cache

| Observation point | Exists | mtime | Size | SHA256 |
| --- | --- | --- | --- | --- |
| Before Update baseline | Yes | `2026-07-31 11:35:16.112457410 +0800` | `282945` bytes | `4e852f43648bbbe9eda02cefdb9f29dad44fb59845c8c93b41560c0ae9e60cff` |
| After Update, before Origin GET | **No** | — | — | — |
| After Origin GET | Yes | `2026-07-31 12:42:11.831644249 +0800` | `283028` bytes | `8f66510a494d1ec51a2655315f90260b1a69b0913c0d4cc78979144e4ac0e3a2` |

The highest-priority success evidence was observed directly: after the real
Update and before any new HTTP request, the English homepage Page Cache file
did not exist. The subsequent server-local Origin GET recreated it with a new
mtime.

| Field | Value |
| --- | --- |
| Request route | `curl --resolve en.shuijingwanwq.com:443:127.0.0.1 https://en.shuijingwanwq.com/` |
| HTTP status | `200` |
| First post | `21022` |
| Contains `/21022/` | Yes |
| Public CDN requested | No |
| Compensation status | **运行时已确认** |

### Runtime conclusion

| Confirmed native gap | Runtime status |
| --- | --- |
| English homepage Page Cache compensation | **运行时已确认** |
| Cross-Host `posts` Object Cache compensation | **运行时已确认** |

**现有 MU Plugin 已通过真实 admin 英文文章 Update 验证，补偿两个已确认的
W3TC multi-Host 原生缺口。**

No Chinese post, term, menu, option, CDN, or other cache behavior was tested.
