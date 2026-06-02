# App Domain Native Refactor Plan

## 目标

把当前 `App域名管理 / 节点入口下发` 从“可用补丁”升级成“接近 xiao v2b 原生二开模块”的形态。

最终效果：

- 后台 UI 看起来像 xiao v2b 作者原生写的功能中心页面，而不是额外注入的一块自定义工具。
- 后端代码结构尽量贴近现有 `Controller / Request / Service / Model / Route` 风格。
- App 专用订阅、节点入口替换、API 多域名、规则映射、节点可见性彼此边界清晰。
- 补丁包能复装到其他 xiao v2b / v2board 后端，安装、验证、回滚流程稳定。
- 普通网页订阅、第三方客户端订阅和 App 专用订阅继续隔离，不互相污染。

## 当前状态判断

当前补丁已经具备基础能力：

- `AppController`
  - `app/bootstrap`
  - `app/getConfig`
  - `app/getVersion`
- `ClientController`
  - `custom_app/subscribe`
  - `custom_app/subscribe?flag=app_meta`
- `ServerService`
  - `getAvailableAppServers`
  - `app_show`
  - `app_domain_replace`
- 管理端
  - `server/app-domain/fetch`
  - `server/app-domain/save`
  - 节点管理列表里的 App 可见 / 域名替换开关
- 安装流程
  - overlay 覆盖
  - 自动备份
  - cache clear
  - Webman 完整重启
  - admin asset 自动 cache bust

但是当前仍有明显“补丁痕迹”：

- `public/assets/admin/app-domain-manager.js` 是独立 DOM 注入，自己写样式、自己监听 hash、自己渲染页面。
- App 域名配置仍主要写入 `config/v2board.php`，不适合承载规则列表、套餐映射、协议范围等复杂配置。
- `AppDomainController` 承担了配置读取、保存、格式化、缓存、重载等多种职责。
- 节点级字段在不同节点类型 Controller / Request 中支持不够统一，需要彻底拉齐。
- UI 还停留在全局配置表单，缺少类似“中转域名分发”的规则化能力。

## 目标功能模型

### 1. 全局 App 域名配置

保留全局配置，但只放稳定开关和默认值：

- `app_domain_enable`
- `app_domain_public_host`
- `app_domain_subscribe_path`
- `app_domain_replace_host`
- `app_api_domain_enable`
- `app_api_domain_hosts`
- `app_api_domain_encrypt_enable`
- `app_api_domain_encrypt_key`
- `app_domain_rule_enable`

用途：

- 作为默认行为。
- 作为老版本客户端兼容入口。
- 当没有匹配规则时兜底。

### 2. 节点级控制

所有节点类型统一支持：

- `app_show`
  - 是否进入 App 专用订阅。
- `app_domain_replace`
  - 是否允许参与 App 入口域名替换。

必须覆盖的节点类型：

- Shadowsocks
- VMess
- Trojan
- VLESS
- Hysteria
- Tuic
- AnyTLS
- V2Node

### 3. 中转 / 入口域名分发规则

新增规则化模型，接近截图里的“中转域名分发”：

- 规则名称
- 启用状态
- 优先级 / 排序
- 绑定域名
- 适用用户组
- 适用套餐
- 适用协议
- 适用节点类型
- 适用节点 ID
- 是否只用于 App
- 是否覆盖节点 host
- 是否覆盖订阅域名
- 备注

规则匹配顺序：

1. 过滤关闭规则。
2. 按用户组 / 套餐 / 节点类型 / 协议 / 节点 ID 匹配。
3. 按 `sort ASC, id ASC` 取第一条最具体规则。
4. 没有命中时回落全局 App 域名配置。

### 4. 适用协议

建议先支持协议范围，不直接改协议模板语义：

- `vmess`
- `vless`
- `trojan`
- `shadowsocks`
- `hysteria`
- `tuic`
- `anytls`
- `v2node`

后续如需细分，可再扩展：

- transport: `tcp`, `ws`, `grpc`, `xhttp`
- tls/reality
- network settings

第一版不要过度深入协议字段，避免影响稳定性。

## 推荐数据结构

### 新增表：`v2_app_domain_rules`

```sql
CREATE TABLE `v2_app_domain_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `enable` tinyint(1) NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `domain` varchar(255) NOT NULL,
  `user_group_ids` json DEFAULT NULL,
  `plan_ids` json DEFAULT NULL,
  `server_types` json DEFAULT NULL,
  `server_ids` json DEFAULT NULL,
  `protocols` json DEFAULT NULL,
  `replace_node_host` tinyint(1) NOT NULL DEFAULT 1,
  `replace_subscribe_host` tinyint(1) NOT NULL DEFAULT 0,
  `remark` varchar(255) DEFAULT NULL,
  `created_at` int DEFAULT NULL,
  `updated_at` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_enable_sort` (`enable`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

说明：

- `domain` 只存 host，不存协议。
- JSON 字段为空表示不限制。
- `plan_ids` 如果目标后端没有套餐字段，则忽略。
- `protocols` 第一版可以和 `server_types` 等价，后续再精细化。

### 模型

新增：

- `app/Models/AppDomainRule.php`

风格参考现有 Server 模型：

- `$table = 'v2_app_domain_rules'`
- `$dateFormat = 'U'`
- `$guarded = ['id']`
- JSON 字段 cast 为 array

### Schema 安装

补丁包新增：

- `sql/app_domain_rules.sql`
- `scripts/preflight.php`
- `scripts/migrate_app_domain.php`

安装策略：

- `install.sh` 不直接强制改库。
- 默认先跑 preflight，输出缺失字段/表。
- 明确执行 migrate 脚本才创建表/补字段。
- 对生产环境提供 SQL 文件，方便人工审计后执行。

## 推荐代码结构

### Admin Controller

当前：

- `App\Http\Controllers\V1\Admin\Server\AppDomainController`

建议保留路由位置，但拆职责：

- `AppDomainController`
  - `config`
  - `saveConfig`
  - `rules`
  - `saveRule`
  - `dropRule`
  - `sortRule`
  - `options`

不要让 Controller 自己处理复杂匹配逻辑。

### Requests

新增：

- `App\Http\Requests\Admin\AppDomainConfigSave`
- `App\Http\Requests\Admin\AppDomainRuleSave`
- `App\Http\Requests\Admin\AppDomainRuleUpdate`

让校验逻辑从 Controller 移出去，贴近现有 xiao v2b 风格。

### Service

新增：

- `App\Services\AppDomainService`

职责：

- 读取全局配置
- 读取规则列表
- 规范化 host/path
- 给用户和节点匹配规则
- 计算 App 订阅 host
- 计算节点下发 host
- 生成 bootstrap payload
- 给 admin preview 提供样本数据

`ServerService` 只保留节点列表和可用节点筛选，域名替换逻辑调用 `AppDomainService`。

推荐方法：

```php
class AppDomainService
{
    public function getConfig(): array;
    public function saveConfig(array $data): bool;
    public function getRules(): array;
    public function saveRule(array $data): bool;
    public function matchRule(User $user, array $server): ?AppDomainRule;
    public function applyToServer(User $user, array $server): array;
    public function buildBootstrap(User $user): array;
    public function buildSubscribeUrl(User $user): string;
}
```

### ServerService

目标写法：

```php
public function getAvailableAppServers(User $user)
{
    $servers = array_filter($this->getAvailableServers($user), function ($server) {
        return (int) ($server['app_show'] ?? 1) === 1;
    });

    $appDomainService = new AppDomainService();
    return array_map(function ($server) use ($user, $appDomainService) {
        return $appDomainService->applyToServer($user, $server);
    }, $servers);
}
```

这样 `ServerService` 不知道规则细节，后续规则扩展不会继续污染它。

### Client AppController

目标：

- `getBootstrap` 调 `AppDomainService::buildBootstrap`
- `getVersion` 只负责版本输出，bootstrap 部分从 service 取
- `getConfig` 使用 App 专用节点，但不要直接承担域名规则逻辑

### Client ClientController

目标：

- 普通 `subscribe` 保持不变。
- `subscribeForApp` 只负责拿 App 节点，再复用现有协议输出。
- `app_meta` 仍只走 App 专用模板。

## 后台 UI 方案

### 第一阶段：保持 overlay 注入，但 UI 原生化

由于当前补丁包没有 admin 前端源码，只能改编译后的 `umi.js` 和追加 JS。

先把 `app-domain-manager.js` 从“自定义卡片工具”改成“仿原后台页面”：

- 使用现有后台容器：
  - `v2board-container-title`
  - `block`
  - `bg-white`
  - `v2board-table-action`
- 减少自定义颜色和大段 CSS。
- 页面结构接近截图：
  - 顶部返回功能列表 / 刷新
  - 功能标题：`中转域名分发`
  - 折叠区：
    - 寄生模式
    - 套餐域名映射
    - 适用协议
  - 表格：
    - 搜索
    - 用户组筛选
    - 添加规则
    - 规则列表

这一阶段仍可继续用注入 JS，但视觉和交互先贴近。

### 第二阶段：尽量合并进 `umi.js`

把 App 域名页面做成更像原生路由页面：

- 菜单项在服务器 / 功能中心下。
- 路由名固定：
  - `/server/app-domain`
  - 或 `/feature/app-domain`
- 去掉 `plugin` 命名，减少外挂感。

### 第三阶段：如果拿到前端源码，重建正式 React 页面

如果后续能拿到 xiao v2b admin 前端源码：

- 新增页面组件
- 新增 model/effects
- 使用 Ant Design 原组件
- 正式 build `umi.js`
- overlay 只覆盖 build 产物

这是最接近原生的终局方案。

## Admin API 设计

推荐接口：

```text
GET  /api/v1/{secure_path}/server/app-domain/config
POST /api/v1/{secure_path}/server/app-domain/config

GET  /api/v1/{secure_path}/server/app-domain/rules
POST /api/v1/{secure_path}/server/app-domain/rule/save
POST /api/v1/{secure_path}/server/app-domain/rule/drop
POST /api/v1/{secure_path}/server/app-domain/rule/sort

GET  /api/v1/{secure_path}/server/app-domain/options
```

兼容旧接口：

```text
GET  /server/app-domain/fetch
POST /server/app-domain/save
```

旧接口保留一段时间，避免当前测试平台 UI 或脚本断掉。

## App API 设计

保留当前接口：

```text
GET /api/v1/client/app/bootstrap?token=...
GET /api/v1/client/app/getConfig?token=...
GET /api/v1/client/app/getVersion?token=...
GET /api/v1/client/custom_app/subscribe?token=...
GET /api/v1/client/custom_app/subscribe?token=...&flag=app_meta
```

可新增 V2 App API 兼容层：

```text
GET /api/v2/app/bootstrap?token=...
GET /api/v2/app/subscribe?token=...
GET /api/v2/app/profile?token=...
GET /api/v2/app/client/version
```

V2 层只做包装，底层仍调用同一个 `AppDomainService`。

## 插入其他后端的要求

补丁包要做到：

- 不依赖当前测试站点域名。
- 不打印 token。
- 安装前可 preflight。
- 文件覆盖前有备份。
- DB 变更可审计。
- 支持回滚。
- 支持重复安装。
- 支持 Webman 完整重启。
- 支持 admin asset 自动 cache bust。
- 支持缺字段时给出明确提示。

安装推荐流程：

```bash
php82 scripts/preflight.php /path/to/site
php82 scripts/migrate_app_domain.php /path/to/site --dry-run
php82 scripts/migrate_app_domain.php /path/to/site --apply
bash install.sh /path/to/site
bash verify.sh /path/to/site https://panel.example.com secure_path user_token admin_auth
```

## 分阶段实现计划

### Phase 0：冻结当前稳定线

目标：

- 当前 `82408ed` + cache bust/Webman restart 优化作为稳定基线。
- 打 tag，例如：
  - `v1.0.3-overlay-stable`

任务：

- 提交当前文档和 installer/cache bust 优化。
- README 说明当前稳定能力。
- 不扩展功能。

验收：

- 测试平台当前功能不回退。
- App 路由和 admin 页面继续可用。

### Phase 1：字段和保存一致性

目标：

- 所有节点类型完整支持 `app_show` / `app_domain_replace`。
- Controller、Request、admin table update 行为一致。

任务：

- 审计 8 类节点 Controller。
- 审计 Request 类。
- 审计 `umi.js` 节点管理 update action。
- 更新 `runtime_verify.php` 和 `scenario_verify.php`。

验收：

- 每类节点都能切换 App 可见。
- 每类节点都能切换域名替换。
- `app_show=0` 不进入 App 订阅。
- `app_domain_replace=0` 不替换 host。

### Phase 2：服务层原生化

目标：

- 新增 `AppDomainService`。
- `ServerService` 和 `AppController` 不再直接处理复杂域名规则。

当前进度：

- 已新增 `App\Services\AppDomainService`，集中处理配置读取、保存、host/path 规范化、App 订阅 URL、bootstrap payload、多 API 域名加密字段和 App 节点 host 替换。
- `AppController@getBootstrap` / `getVersion` 已改为调用 service，Controller 只保留请求可用性判断和版本响应结构。
- `AppDomainController@fetch` / `save` 已改为调用 service，原本分散在 Controller 内的配置写入、cache clear、Webman reload 逻辑收敛到 service。
- `ServerService@getAvailableAppServers` 已改为调用 `AppDomainService::applyToServer`，后续新增规则表时不需要继续污染节点筛选逻辑。
- `Helper::getAppSubscribeUrl` 保留兼容入口，但内部委托给 `AppDomainService::buildSubscribeUrl`。

任务：

- 提取 host/path normalize。
- 提取 bootstrap payload。
- 提取 App 订阅 URL。
- 提取节点 host 替换逻辑。
- 保持旧配置兼容。

验收：

- App bootstrap / getConfig / getVersion / subscribe / app_meta 全部 200。
- 普通订阅输出不变。
- Service 单点控制域名规则。

### Phase 3：规则表和规则 API

目标：

- 新增 `v2_app_domain_rules`。
- 支持套餐 / 用户组 / 协议 / 节点范围映射。

任务：

- 已新增 `AppDomainRule` Model。
- 已新增 `AppDomainRuleSave` / `AppDomainRuleSort` Request。
- 已新增 `config` / `rules` / `rule/save` / `rule/drop` / `rule/sort` / `options` Controller 方法和 Admin 路由。
- 已新增 `sql/app_domain_rules.sql`、`scripts/preflight.php`、`scripts/migrate_app_domain.php`。
- 已在 `AppDomainService` 中加入规则匹配、规则 CRUD、options、订阅域名规则匹配和无规则回退全局配置。

验收：

- 可以增删改查规则。
- 规则匹配优先级稳定。
- 没有规则时回落旧全局配置。

### Phase 4：UI 原生化第一版

目标：

- 页面结构接近截图里的“功能中心 / 中转域名分发”。
- 表格化规则管理。
- 搜索、用户组筛选、添加规则、折叠配置区。

任务：

- 已重写 `app-domain-manager.js` 的 DOM 结构和样式。
- 已尽量复用后台现有 `block` / `btn` / `form-control` / `table` 风格。
- 菜单命名已从 `App域名管理` 调整为 `中转域名分发`。
- 主路由已迁移到 `/server/app-domain`，旧 `/server/app-domain-plugin` 继续重定向兼容。
- 页面已拆成全局配置、分发规则表、规则编辑弹窗。
- 规则保存、删除、排序后会重新拉取列表。

验收：

- UI 不像外挂页。
- 表格和按钮视觉接近原后台。
- 规则保存后能立即刷新列表。

### Phase 5：安装器和发布工程化

目标：

- 补丁能像二开项目一样插到其他后端。

任务：

- `install.sh --dry-run`
- 文件 checksum diff summary
- `preflight.php`
- `migrate_app_domain.php`
- `verify.sh` 扩展规则测试
- `uninstall.sh` 支持 DB 回滚提示
- GitHub Actions 做基本 lint/package

验收：

- 新后端可以按文档装。
- 失败能回滚。
- 每次发布有 tag 和 changelog。

### Phase 6：GitHub 同步与版本发布

目标：

- GitHub 仓库成为补丁包 source of truth。

任务：

- 整理未跟踪的 `platform/` 是否纳入独立目录或拆仓。
- 提交 Phase 0 文档与 cache bust 优化。
- 每个 Phase 单独提交。
- 每个可用节点打 tag。
- README 增加安装矩阵：
  - 已验证 xiao v2b 版本
  - PHP 版本
  - Webman / FPM 模式
  - 需要的 DB 字段

推荐 tag：

```text
v1.0.3-overlay-stable
v1.1.0-native-service
v1.2.0-domain-rules
v1.3.0-native-admin-ui
```

## 风险点

- 编译后的 `umi.js` 继续手改，长期维护成本高。
- 不同 xiao v2b 后端的节点类型和字段可能不完全一致。
- `config/v2board.php` 写配置在多实例环境不够优雅。
- 规则表引入后，DB 迁移和回滚需要谨慎。
- 套餐字段在不同 fork 中可能命名不同，需要 preflight 探测。

## 推荐原则

- 先兼容，再替换。
- 先规则服务层，再 UI 大改。
- 保留旧接口，新增新接口。
- 所有 DB 变更都提供 dry-run。
- 所有安装都输出备份路径。
- 所有发布都先在测试平台跑完整 HTTP 验证。
