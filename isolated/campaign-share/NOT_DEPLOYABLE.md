# 不可部署

这个目录不是 qenda.cn 现网活动系统，也不能当作它的补丁上线。

当前仓库原先只有 qenda.io 的公开说明，没有 `CampaignService`、`verify.php`、`codes.php`、`checkkey`、管理端或推广者业务。这里的代码是默认关闭的隔离合约，测试只用本机 SQLite 夹具。

- 仓库自带的网页入口 `public/index.php` 会强制关闭功能，不打开数据库。
- 生产配置里的验证器只会失败闭合。测试替身不能在非测试环境创建。
- `scripts/rollback.php` 只写关闭标记，不连接数据库，也不执行会删钥匙的 down SQL。
- 不要把本目录同步到门控机，不要把它标成可部署。
