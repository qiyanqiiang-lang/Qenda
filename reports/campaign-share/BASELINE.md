# 仓库基线对应性（2026-11-10）

本仓库 `qiyanqiiang-lang/Qenda` 的 `main`（`75e82a1`）只有一份 qenda.io 公开说明 `README.md`，MD5 `64b9ebb9b196612b9ad93859faa24385`。仓库自述写明托管服务源码不在这里发布。

对照现网活动系统，下列对象在本仓库中不存在，因此不能把本仓库当成门控机上的 PHP 活动系统：

| 现网对象 | 本仓库 |
| --- | --- |
| `Caishen/api/verify.php` | 不存在。活锚点 `fcda5b23af14045083e4b6fa57d6d792` 只作对照，未重算，未接触生产 |
| `Caishen/api/codes.php` | 不存在。活锚点 `064bab4092bb9d294c763e67e52b9a7c`，同样未重算 |
| `CampaignService` / `api/campaign/key-generate.php` | 不存在 |
| `checkkey`、管理端、推广者列表接口 | 不存在 |
| 号码持有验证 / 短信实现 | 不存在 |
| 设计系统、页面样式、组件 | 不存在 |

`README.md` 未被修改。全仓库没有名为 `verify.php` 或 `codes.php` 的文件。本次没有访问生产、没有读取 SSH 私钥、cookie、数据库配置或个人数据。

Refero 屏幕检索返回 `NO_SUBSCRIPTION`。本仓库没有可锁定的 Qenda 设计系统。介绍页因此只保留结构和方案中的中文文案，没有做视觉稿。

结论：本分支的实现是隔离合约，不是现网补丁，不能部署到 qenda.cn。
