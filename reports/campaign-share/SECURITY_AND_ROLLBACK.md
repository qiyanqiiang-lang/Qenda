# 安全边界、默认关闭、独立路由、迁移与回滚

## 默认关闭

- `isolated/campaign-share/config/default.php` 中 `enabled` 为 `false`，验证器为 `fail_closed`。
- `public/index.php` 只走 `AppFactory::fromWeb()`，该方法忽略配置里的开启值，强制关闭，并且不创建数据库连接。
- 环境变量本身不能把网页入口打开。
- 非 `test` 环境如果注入测试验证器，构造时直接拒绝。
- 夹具脚本 `scripts/serve-fixture.php` 只接受本机回环地址，以及 `/tmp` 或包内 `var/` 下的 SQLite 文件；验证器仍然是失败闭合，不能发出密钥。

关闭时，新路由返回 `404 {"ok":false,"message":"not_found"}`，不写库。测试用没有表的空 SQLite 连接证明这一点：一旦误查库就会变成 500，而实际结果是 404。

## 独立路由

新路由全部在 `/campaign/share.*`、`/campaign/promoter.html` 和 `/api/campaign-share/v1/` 下。路由表里没有 `verify.php`、`codes.php`、`key-generate`，也没有浏览器可以自报「已使用」的接口。

领取顺序是：推广码只确定来源；打开页面不发钥；提交手机号只创建挑战；持号验证通过后才发 HttpOnly、Secure、SameSite=Strict 会话；领取只读这个会话。请求体里的手机号、用户编号、推广者编号和 `used` 不参与授权。

推广者列表、详情、搜索、导出和推广者页面的字段只有序号、密钥标识、状态、时间和来源推广码。查询手机号或掩码会在读库前拒绝，响应不回显号码。

## 迁移与回滚

- `sql/sqlite/` 已在测试里执行 up、写入一把密钥、再执行 down。down 会删掉隔离表和其中的密钥，所以它不是回滚手段。
- `sql/mysql/` 只是供以后单独批准的审阅稿，本次没有连接 MySQL，也没有执行。
- 两份 up 只创建 `campaign_share_*` 新表，不写入种子，不出现旧密钥表名。
- `scripts/rollback.php` 默认 dry-run。`--apply` 只写 `config/force-off.flag`，并删除 `config/local-enable.php`（若存在）。它拒绝 `--database`、拒绝含 `1panel` 等字样的路径，源码里没有 PDO、MySQL 或 SSH 调用。
- 强制关闭之后，测试库里已经领出的密钥行还在。

已领密钥的保留策略：关闭新领，不回收已发出的隔离池密钥，不改旧手工钥。down SQL 会删除隔离池密钥，生产回滚不得执行它。
