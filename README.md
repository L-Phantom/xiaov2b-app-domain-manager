# xiaov2b App Domain Manager Pack

这是一个给 `xiaov2b / v2board` 管理后台使用的可复装补丁包。

目标：
- 在后台 `服务器` 分类下增加 `App域名管理`
- 增加 `App 专用订阅`、`App bootstrap`、`App API 多域名`
- 增加 `App 专用完整 Meta 订阅`，供客户端第二阶段静默升级使用
- 在节点管理里增加 `App可见` 开关
- 入口域名替换由规则页统一管理，可按节点、套餐、权限组分发
- 让普通网页订阅与 App 专用订阅分流
- 面板升级后可再次一键部署

当前补丁基线：
- upstream: `wyx2685/v2board`
- base commit: `e384825b`

## 当前生产语境

- 配套客户端已经迭代到正式交付线 `1.0.3`，不是候选交付状态。
- 客户端关键版本提交：
  - `0e87b0b`：v1.0.3，强制更新、图标修复、注册/忘记密码修复、版本号升级。
  - `cecd95b`：Windows VC runtime DLL、Dio timeout、关键诊断日志、`apply_branding.dart` 图标拉取修复。
- 当前打包方式：
  - macOS 本地打包
  - Android 本地打包
  - Windows GitHub Actions 打包
- 后续这个仓库按生产维护 / 测试平台灰度思路推进，优先保证可回滚、可验证、可重复部署。
- 2026-06-03 已补齐 App API 域名池协议处理：
  - `185.200.65.62:3883` 这类 IP:端口 endpoint 默认按 `http://` 下发，不再强制拼成不可用的 `https://`。
  - `/api/v2/app/bootstrap`、App 域名管理后台预览、V1 App bootstrap 统一保留 endpoint 协议。
  - `app.user` 中间件已纳入补丁清单，避免 V2 App 登录态接口在 Workerman 下因 middleware alias 未注册返回 502。

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
- `scripts/preflight.php`
  检查目标站点、PHP 能力、规则表和节点字段状态
- `scripts/migrate_app_domain.php`
  创建 App 域名规则表，默认 dry-run，明确传 `--apply` 才执行
- `sql/app_domain_rules.sql`
  App 域名分发规则表 SQL
- `overlay/resources/rules/app.meta.clash.yaml`
  App 第二阶段完整 Meta 模板
- `platform/`
  Brand Manager / 打包后台，用于品牌配置、manifest、安装包上传、发布记录、强制更新与 `branding.dart` 预览。
  当前本地 `platform/` 仍是未跟踪目录，生产数据不在本地工作区。

## 打包后台说明

`platform/` 是配套的打包后台 / Brand Manager，核心能力包括：

- 品牌配置：品牌名、Panel URL、API 域名池、Manifest Secret、Subscribe Sign Secret
- 分发配置：OSS Manifest URLs、落地页、客服 ID
- 资源上传：品牌图标、Android APK、macOS DMG/ZIP、Windows EXE/MSIX/ZIP
- 版本发布：版本号、更新日志、强制更新、发布历史
- 客户端构建辅助：生成 encrypted manifest 与 `branding.dart` 预览

生产化前建议先决定是否把 `platform/` 正式纳入仓库，并补齐：

- 部署文档
- 默认密码 / 凭据初始化策略
- 包文件 SHA256 / 大小 / 上传时间记录
- 发布回滚说明
- 固定 Public Base URL / HTTPS / 缓存头

## 安装

```bash
cd /path/to/app-domain-manager-package
php82 scripts/preflight.php /path/to/v2board-root
php82 scripts/migrate_app_domain.php /path/to/v2board-root --dry-run
php82 scripts/migrate_app_domain.php /path/to/v2board-root --apply
bash install.sh /path/to/v2board-root
```

如果不传路径，会尝试使用当前目录（要求当前目录下存在 `artisan`）。

安装时会：
- 可选创建 `v2_app_domain_rules` 规则表
- 备份原文件到目标站点下的 `.app-domain-manager-backups/`
- 覆盖 `overlay/` 中的文件
- 执行 `view:clear`
- 执行 `config:clear` 与 `config:cache`
- 如果检测到 Webman，优先执行完整 `stop/start`，必要时才回退到进程 reload

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
- 如果规则表存在，会在事务里临时创建规则，验证规则匹配优先级
- 输出普通节点样本与 App 节点样本
- 最后自动回滚，不写入数据库

## App 域名规则 API

第三阶段新增了规则表和后端 API，供后续原生化后台 UI 使用。

兼容旧接口：
- `GET /api/v1/{secure_path}/server/app-domain/fetch`
- `POST /api/v1/{secure_path}/server/app-domain/save`

新增接口：
- `GET /api/v1/{secure_path}/server/app-domain/config`
- `POST /api/v1/{secure_path}/server/app-domain/config`
- `GET /api/v1/{secure_path}/server/app-domain/rules`
- `POST /api/v1/{secure_path}/server/app-domain/rule/save`
- `POST /api/v1/{secure_path}/server/app-domain/rule/drop`
- `POST /api/v1/{secure_path}/server/app-domain/rule/sort`
- `GET /api/v1/{secure_path}/server/app-domain/options`

规则表存在且 `app_domain_rule_enable=1` 时，`AppDomainService` 会优先按规则匹配用户组、套餐和节点范围。没有命中规则的节点保持原始入口地址，不再自动回落全局 App 域名配置；旧的全局替换配置仅在规则功能关闭时作为兼容路径生效。

后台 UI 的日常使用方式：
- 在 `入口域名规则` 中填写入口域名。
- 勾选需要命中的用户组、套餐和节点；节点列表直接来自现有节点表。
- 不勾选节点时表示该规则对匹配用户范围内的全部节点生效。
- 节点管理页不再展示独立的 `域名替换` 列，避免形成第二套入口域名策略；底层字段仍保留用于旧站点兼容和紧急兜底。

后台入口：
- 主入口：`/{secure_path}#/server/app-domain`
- 兼容入口：`/{secure_path}/server/app-domain-plugin` 会跳到新入口

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

原生化重构路线见：
- `NATIVE_REFACTOR_PLAN.md`

推荐流程：

1. 先在测试平台手动升级原版 `wyx2685/v2board` / xiaov2b。
2. 升级完成后，先检查站点文件、PHP CLI、后台安全路径、测试用户 token、数据库字段。
3. 在新版本站点上重新执行 `bash install.sh /path/to/site`
4. 再执行 `bash verify.sh /path/to/site ...`
5. 如果验证失败，只重新对齐这几个高风险文件：
   - `ClientController.php`
   - `ClashMeta.php`
   - `app.meta.clash.yaml`
   - 以及你原来 `AppDomain / bootstrap / custom_app subscribe` 那几处 overlay
   - 以及 2026-05-12 后新增的节点级 `app_show / app_domain_replace` 控制器、Request 与 admin asset 文件

这样做的原因是：

- 面板升级后，大部分文件并不会和这份补丁冲突
- 真正的冲突面集中在少数订阅入口和协议模板文件
- 把 App 专用完整 Meta 通道和节点级 App 开关单独收口后，后续维护成本会明显比“大面积魔改 default.clash.yaml”更低

## 下一轮测试平台计划

当前计划：

1. 用户先手动完成测试平台升级。
2. 升级后由 Codex 介入，检查升级后的真实文件与 schema。
3. 再安装本 overlay 补丁包。
4. 验证 App bootstrap、App config、App version、App-only subscribe、App meta subscribe、admin fetch/save。
5. 重点验证：
   - `app_show=0` 不进入 App 专用订阅
   - `app_domain_replace=0` 在全局替换开启时仍保留原 host
   - 普通网页订阅不被 App 专用模板污染
   - 配置保存后 config cache / webman reload 生效
6. 通过后再整理生产发布步骤与回滚命令。

## 当前边界

这不是原生插件系统下的“热插拔插件”，而是一个可复装补丁包。

原因：
- 当前 `xiaov2b / v2board` 后台没有现成的、能完整接管路由/菜单/节点管理/订阅逻辑的通用插件框架
- 所以升级面板后，最稳的方案是重新执行一次 `install.sh`

这也是这份包存在的目的。

更细的升级维护流程见：
- `MAINTENANCE.md`
