# xiaov2b App Domain Manager Pack

这是一个给 `xiaov2b / v2board` 管理后台使用的可复装补丁包。

目标：
- 在后台 `服务器` 分类下增加 `App域名管理`
- 增加 `App 专用订阅`、`App bootstrap`、`App API 多域名`
- 增加 `App 专用完整 Meta 订阅`，供客户端第二阶段静默升级使用
- 在节点管理里增加 `App可见` 开关
- 让普通网页订阅与 App 专用订阅分流
- 面板升级后可再次一键部署

当前补丁基线：
- upstream: `wyx2685/v2board`
- base commit: `e384825b`

## 目录说明

- `overlay/`
  直接覆盖到面板根目录的文件
- `manifest.txt`
  本补丁涉及的文件清单
- `install.sh`
  安装补丁并自动备份原文件
- `uninstall.sh`
  按最近一次备份回滚
- `verify.sh`
  做一轮安装后验证
- `scripts/runtime_verify.php`
  校验当前面板内的 App 域名管理运行状态
- `scripts/scenario_verify.php`
  做一轮不落库的场景验证，确认普通订阅与 App 订阅分流逻辑
- `overlay/resources/rules/app.meta.clash.yaml`
  App 第二阶段完整 Meta 模板

## 安装

```bash
cd /path/to/app-domain-manager-package
bash install.sh /path/to/v2board-root
```

如果不传路径，会尝试使用当前目录（要求当前目录下存在 `artisan`）。

安装时会：
- 备份原文件到目标站点下的 `.app-domain-manager-backups/`
- 覆盖 `overlay/` 中的文件
- 执行 `view:clear`
- 执行 `config:clear` 与 `config:cache`
- 如果检测到 `WEBMANPID`，自动发送 `SIGUSR1` 热重载

## 回滚

```bash
cd /path/to/app-domain-manager-package
bash uninstall.sh /path/to/v2board-root
```

默认回滚最近一次安装生成的备份。

## 验证

只做本地运行时校验：

```bash
bash verify.sh /path/to/v2board-root
```

如果要顺带测 HTTP 接口：

```bash
bash verify.sh /path/to/v2board-root \
  https://panel.example.com \
  YOUR_SECURE_PATH \
  YOUR_USER_TOKEN \
  YOUR_ADMIN_AUTH
```

参数说明：
- 第 1 个参数：站点根目录
- 第 2 个参数：站点基础 URL
- 第 3 个参数：后台安全路径
- 第 4 个参数：可用用户 token
- 第 5 个参数：后台 `authorization` 值，可选

如果要验证“普通订阅保留原 host，App 订阅改成 App 入口域名”：

```bash
php82 scripts/scenario_verify.php /path/to/v2board-root app-edge.example.com
```

这条命令会：
- 在事务里临时把一个节点视为 `app_show=1`
- 只在运行时打开 `app_domain_enable=1`
- 临时注入 `app_domain_replace_host`
- 输出普通节点样本与 App 节点样本
- 最后自动回滚，不写入数据库

## 给 FlClash / 自研客户端的联调入口

当前 App 端相关接口：
- `GET /api/v1/client/app/bootstrap?token=...`
- `GET /api/v1/client/app/getConfig?token=...`
- `GET /api/v1/client/app/getVersion?token=...`
- `GET /api/v1/client/custom_app/subscribe?token=...`
- `GET /api/v1/client/custom_app/subscribe?token=...&flag=app_meta`

推荐联调顺序：
1. 客户端登录后先请求 `bootstrap`
2. 如果 `api_domain_enable=1`，按 `api_domains` 或 `api_urls` 做轮询
3. 用 `subscribe_url` 拉取 App 专用订阅
4. 用 `getConfig` 拉取 App 专用 Clash 配置
5. 核心和首阶段 profile 可用后，再请求 `flag=app_meta` 获取完整 Meta 配置

## 二阶段完整订阅说明

这次补丁把 App 的完整规则升级收成了一个最小补丁面：

- `overlay/app/Http/Controllers/V1/Client/ClientController.php`
  增加 `flag=app_meta` 分支
- `overlay/app/Protocols/ClashMeta.php`
  支持按调用方覆盖模板路径
- `overlay/resources/rules/app.meta.clash.yaml`
  作为 App 第二阶段完整 Meta 模板

设计目标：

- 首阶段继续沿用现有 `custom_app/subscribe + app/getConfig`
- 第二阶段只给 App 额外提供一份完整 Meta 配置
- 不污染普通 `flag=meta` 或普通第三方客户端使用的 `default.clash.yaml`

如果目标站点没有安装这份补丁，客户端仍然可以回退到普通 `flag=meta`。

## 后续维护计划

这份仓库推荐按“overlay 复装包”维护，而不是长期手改线上文件。

推荐流程：

1. 先升级原版 `wyx2685/v2board`
2. 在新版本站点上重新执行 `bash install.sh /path/to/site`
3. 再执行 `bash verify.sh /path/to/site ...`
4. 如果验证失败，只重新对齐这几个高风险文件：
   - `ClientController.php`
   - `ClashMeta.php`
   - `app.meta.clash.yaml`
   - 以及你原来 `AppDomain / bootstrap / custom_app subscribe` 那几处 overlay

这样做的原因是：

- 面板升级后，大部分文件并不会和这份补丁冲突
- 真正的冲突面集中在少数订阅入口和协议模板文件
- 把 App 专用完整 Meta 通道单独收口后，后续维护成本会明显比“大面积魔改 default.clash.yaml”更低

## 当前边界

这不是原生插件系统下的“热插拔插件”，而是一个可复装补丁包。

原因：
- 当前 `xiaov2b / v2board` 后台没有现成的、能完整接管路由/菜单/节点管理/订阅逻辑的通用插件框架
- 所以升级面板后，最稳的方案是重新执行一次 `install.sh`

这也是这份包存在的目的。

更细的升级维护流程见：
- `MAINTENANCE.md`
