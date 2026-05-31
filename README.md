# FriendLinks 友情链接插件使用说明

> **适用版本**：Typecho 1.2+  
> **插件版本**：v2.3.0  
> **作者**：Astrsource  
> **项目地址**：https://astrsource.com

---

## 目录

1. [插件概述](#一插件概述)
2. [功能特性](#二功能特性)
3. [安装与启用](#三安装与启用)
4. [数据库表结构](#四数据库表结构)
5. [后台管理面板](#五后台管理面板)
6. [插件配置详解](#六插件配置详解)
7. [前台调用方式](#七前台调用方式)
8. [模板与样式定制](#八模板与样式定制)
9. [定时任务（Cron）](#九定时任务cron)
10. [缓存机制](#十缓存机制)
11. [常见问题](#十一常见问题)
12. [卸载与数据清理](#十二卸载与数据清理)

---

## 一、插件概述

FriendLinks 是一款专为 Typecho 开发的友情链接管理插件，支持**自动抓取网站信息**（标题、描述、Favicon、存活状态）、**分类管理**、**缓存加速**、**定时任务更新**以及**高度自定义的模板渲染**。

插件采用前后台分离设计：
- **后台**：提供完整的可视化面板，支持链接/分类的增删改查、批量更新、分页筛选
- **前台**：通过短代码或模板函数输出，支持自定义 CSS 与 HTML 模板

---

## 二、功能特性

| 功能模块 | 说明 |
|---------|------|
| **自动抓取** | 添加/更新链接时自动抓取目标网站的标题、描述、图标和存活状态 |
| **分类管理** | 支持多分类管理，删除分类时链接自动移至「未分类」 |
| **存活检测** | 自动检测链接 HTTP 状态，支持一键清理异常链接 |
| **多级缓存** | JSON 数据缓存 + HTML 渲染缓存，大幅提升前台加载速度 |
| **定时任务** | 支持 Cron 定时批量更新所有链接信息，可配置密钥验证 |
| **访客排序** | 可选开启前台排序下拉框，访客可切换排序方式（偏好记录于 Cookie） |
| **短代码支持** | 文章/页面内使用 `[friendlinks]` 短代码快速插入 |
| **模板函数** | 提供 `FriendLinks_Plugin::output()` 供主题模板直接调用 |
| **自定义模板** | 支持自定义容器模板、卡片模板和 CSS 样式 |
| **分页管理** | 后台支持分页展示、每页条数切换、多维度排序 |

---

## 三、安装与启用

### 3.1 环境要求

- PHP 7.4+
- PHP **cURL** 扩展（必须）
- Typecho 1.2 或更高版本
- MySQL / MariaDB / SQLite（插件自动适配）

### 3.2 安装步骤

1. **下载插件**并解压，将文件夹重命名为 `FriendLinks`
2. **上传目录**至 Typecho 的插件目录：`/usr/plugins/`
3. **登录后台** → 控制台 → 插件 → 找到「FriendLinks」→ 点击**启用**
4. 启用后插件会自动创建数据表和缓存目录

### 3.3 目录结构

```
/usr/plugins/FriendLinks/
├── Plugin.php          # 插件主文件（核心逻辑）
├── Action.php          # Ajax 接口与 Cron 入口
├── panel.php           # 后台管理面板
├── cache/              # 缓存目录（自动创建）
│   ├── friendlinks.cache.json          # 数据缓存
│   └── friendlinks_rendered_*.html   # 渲染缓存
```

---

## 四、数据库表结构

插件启用后会自动创建两张数据表：

### 4.1 链接表 `{prefix}friendlinks`

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int(11) | 主键，自增 |
| `url` | varchar(255) | 网站地址（必填） |
| `title` | varchar(255) | 网站标题 |
| `description` | text | 网站描述 |
| `icon` | varchar(255) | 图标 URL |
| `status` | tinyint(1) | 状态：1=显示，0=隐藏 |
| `sort` | int(11) | 排序值，数字越小越靠前 |
| `category_id` | int(11) | 所属分类 ID，NULL 表示未分类 |
| `last_update` | int(11) | 最后更新时间戳 |
| `created` | int(11) | 创建时间戳 |
| `alive` | tinyint(1) | 存活状态：1=正常，0=异常，NULL=未知 |
| `alive_checked` | int(11) | 存活检测时间戳 |

**索引**：`idx_status_sort` (status, sort)、`idx_category_id` (category_id)

### 4.2 分类表 `{prefix}friendlinks_categories`

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int(11) | 主键，自增 |
| `name` | varchar(100) | 分类名称 |
| `sort` | int(11) | 排序值 |
| `created` | int(11) | 创建时间戳 |

---

## 五、后台管理面板

启用插件后，在后台左侧菜单「管理」→「友情链接」进入管理面板。

### 5.1 分类管理

- **添加分类**：点击「➕ 添加分类」，输入名称和排序值（留空自动递增）
- **编辑分类**：点击分类卡片上的「✏️ 编辑」按钮
- **删除分类**：点击「🗑️ 删除」，该分类下的链接将**自动移至未分类**
- **未分类**：系统内置，不可删除，显示未分类链接数量

### 5.2 链接管理

#### 添加/编辑链接

点击「➕ 添加链接」或列表中的「编辑」按钮，填写以下字段：

| 字段 | 必填 | 说明 |
|------|------|------|
| 网站标题 | 否 | 留空则自动抓取 |
| 网站地址 | **是** | 必须以 http:// 或 https:// 开头 |
| 网站描述 | 否 | 留空则自动抓取 |
| 图标 URL | 否 | 留空则自动探测根目录 favicon |
| 分类 | 否 | 选择已有分类，留空为未分类 |
| 状态 | 否 | 显示 / 隐藏 |
| 排序 | 否 | 数字越小越靠前，0 为默认值 |

> **提示**：保存时插件会自动抓取目标网站信息并填充空白字段。

#### 列表操作

- **更新信息**：单独重新抓取某个链接的网站信息
- **删除**：移除链接（不可恢复）
- **批量更新**：点击工具栏「🔄 更新所有信息」可全量重新抓取（耗时较长，建议用 Cron）

### 5.3 工具栏功能

| 按钮 | 功能 |
|------|------|
| 🔄 更新所有信息 | 全量重新抓取所有可见链接的信息 |
| 🗑️ 刷新缓存 | 立即重建 JSON 数据缓存并清空渲染缓存 |
| 🔢 重整序号 | 将排序值按当前顺序重新排列为 1, 2, 3... |
| 🗑️ 删除异常链接 | 一键删除所有存活状态为「异常」的链接 |

### 5.4 筛选与排序

- **分类筛选**：按全部 / 未分类 / 异常 / 具体分类过滤
- **排序方式**：手动排序 / 添加时间 ↓ / 添加时间 ↑ / 标题 A-Z / 标题 Z-A / 随机
- **分页**：支持 10 / 20 / 50 条每页切换

### 5.5 缓存状态

面板顶部显示当前缓存状态：
- 缓存文件是否存在
- 文件大小
- 最后更新时间
- 剩余有效时间
- 是否已过期

---

## 六、插件配置详解

进入后台 → 控制台 → 插件 → FriendLinks → 设置，可配置以下选项：

### 6.1 基础配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| **缓存时间（秒）** | 604800 | 渲染缓存有效期，默认 7 天。过期后前台自动重建 |
| **请求超时（秒）** | 10 | 抓取网站信息时的 cURL 超时时间 |
| **默认图标 URL** | /favicon.png | 当无法获取到网站图标时显示的默认图标，留空则不显示 |

### 6.2 模板配置

| 配置项 | 说明 |
|--------|------|
| **容器模板** | 外层容器 HTML。占位符：`{cards}`（卡片列表）、`{container_class}`（容器 class） |
| **卡片模板** | 单条链接的 HTML 结构。占位符见下表 |
| **自定义 CSS** | 友情链接卡片的 CSS 样式，直接输出到前台 `<style>` 标签中 |

#### 卡片模板可用占位符

| 占位符 | 输出内容 |
|--------|----------|
| `{url}` | 网站地址（已转义） |
| `{title}` | 网站标题 |
| `{description}` | 网站描述 |
| `{icon}` | 图标 URL |
| `{last_update}` | 最后更新日期（Y-m-d） |
| `{alive}` | 存活状态：正常 / 异常 / 未知 |
| `{category}` | 所属分类名称 |

### 6.3 前台排序配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| **前台排序方式** | 手动排序 | 默认的链接展示顺序 |
| **允许访客选择排序** | 关闭 | 开启后前台显示排序下拉框，访客偏好记录在浏览器 Cookie 中 |

**可选排序方式**：
- 手动排序
- 添加时间（新→旧）
- 添加时间（旧→新）
- 标题 A→Z
- 标题 Z→A
- 随机

### 6.4 其他配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| **跳过异常网站** | 不跳过 | 前台渲染时是否隐藏存活状态为异常的链接（短代码参数可覆盖） |
| **Cron 密钥** | 空 | 设置服务器 Cron 定时访问时的验证密钥 |
| **禁用插件时删除数据表** | 不删除 | ⚠️ 选择删除后，禁用插件将**永久删除**所有链接和分类数据 |

---

## 七、前台调用方式

### 7.1 短代码（推荐用于文章/独立页面）

在文章或页面中插入以下短代码：

```markdown
<!-- 基础用法：显示全部链接 -->
[friendlinks]

<!-- 指定分类 ID（忽略未分类参数） -->
[friendlinks category_id="1"]

<!-- 自定义 CSS 类名 -->
[friendlinks container_class="my-links" card_class="my-card"]

<!-- 包含异常链接 -->
[friendlinks include_dead="1"]

<!-- 仅显示异常链接 -->
[friendlinks include_dead="2"]

<!-- 排除未分类链接 -->
[friendlinks include_uncategorized="0"]

<!-- 仅显示未分类链接 -->
[friendlinks include_uncategorized="2"]
```

#### 短代码参数说明

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `container_class` | string | friendlinks-container | 自定义容器类名 |
| `card_class` | string | 空 | 追加到卡片上的自定义类名 |
| `category_id` | int | null | 按分类 ID 过滤，指定后 `include_uncategorized` 失效 |
| `include_uncategorized` | string | "1" | 1=全部(默认), 0=排除未分类, 2=仅未分类 |
| `include_dead` | string | "0" | 0=按全局配置, 1=强制包含异常, 2=仅异常 |

> **注意**：当指定 `category_id` 时，`include_uncategorized` 参数将被忽略。

### 7.2 模板函数（推荐用于主题模板）

在主题的 PHP 模板文件中调用：

```php
<?php
// 默认输出全部链接
FriendLinks_Plugin::output();

// 仅输出有分类的链接
FriendLinks_Plugin::output('friendlinks-container', '', null, 0);

// 仅输出未分类链接
FriendLinks_Plugin::output('friendlinks-container', '', null, 2);

// 仅输出分类 ID 为 1 的链接
FriendLinks_Plugin::output('friendlinks-container', '', 1);

// 完整参数调用
FriendLinks_Plugin::output(
    $containerClass,      // 容器类名
    $cardClass,           // 卡片追加类名
    $categoryId,          // 分类 ID 过滤
    $uncategorizedMode,   // 未分类模式：1=全部, 0=排除, 2=仅未分类
    $includeDeadMode      // 异常模式：0=按配置, 1=强制包含, 2=仅异常
);
?>
```

#### 参数对照表

| 参数 | 类型 | 默认值 | 取值说明 |
|------|------|--------|----------|
| `$containerClass` | string | 'friendlinks-container' | 容器 CSS 类名 |
| `$cardClass` | string | '' | 卡片追加 CSS 类名 |
| `$categoryId` | int\|null | null | 指定分类 ID，为 null 时不按分类过滤 |
| `$uncategorizedMode` | int | 1 | 0=排除未分类, 1=全部, 2=仅未分类 |
| `$includeDeadMode` | int | 0 | 0=遵循全局配置, 1=强制包含异常, 2=仅异常 |

---

## 八、模板与样式定制

### 8.1 自定义容器模板

在插件配置中修改「容器模板」，例如：

```html
<section class="{container_class}">
    <h3>我的朋友们</h3>
    <div class="links-grid">{cards}</div>
</section>
```

### 8.2 自定义卡片模板

在插件配置中修改「卡片模板」，例如：

```html
<article class="friendlink-card {card_class}">
    <a href="{url}" target="_blank" rel="noopener">
        <img src="{icon}" alt="{title}" loading="lazy">
        <h4>{title}</h4>
        <p>{description}</p>
        <span class="meta">{category} · {last_update}</span>
    </a>
</article>
```

### 8.3 自定义 CSS

在插件配置的「自定义 CSS」中编写样式，例如：

```css
.friendlinks-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

.friendlink-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    transition: transform 0.2s;
}

.friendlink-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
}
```

### 8.4 默认样式变量

插件默认输出的卡片包含以下基础类名，便于覆盖：

- `.friendlinks-container` — 外层容器
- `.friendlink-card` — 单张卡片
- `.result-header` — 卡片头部（图标+标题）
- `.favicon` — 图标容器
- `.title` — 标题
- `.url-display` — 地址
- `.description` — 描述
- `.badge-group` — 底部徽章组
- `.badge-category` — 分类徽章
- `.badge-update` — 更新日期徽章
- `.badge-status` — 存活状态徽章
- `.friendlinks-sort-toolbar` — 访客排序工具栏
- `.friendlinks-sort-select` — 排序下拉框
- `.friendlinks-empty` — 空状态提示

---

## 九、定时任务（Cron）

插件支持通过服务器 Cron 定时批量更新所有链接的存活状态和信息。

### 9.1 获取 Cron URL

在后台管理面板底部「使用说明」区域可查看当前 Cron URL：

```
https://your-domain.com/friendlinks/cron?key=YOUR_SECRET_KEY
```

如果未设置密钥，URL 为：

```
https://your-domain.com/friendlinks/cron
```

### 9.2 配置 Cron 密钥（推荐）

1. 进入插件配置页面
2. 在「Cron 密钥」中设置一个随机字符串（如 `aBc123XyZ`）
3. 保存配置
4. 管理面板中的 Cron URL 会自动附加 `?key=` 参数

### 9.3 添加 Cron 任务

#### Linux / macOS（crontab）

```bash
# 每天凌晨 2 点执行
0 2 * * * curl -s "https://your-domain.com/friendlinks/cron?key=YOUR_SECRET_KEY" > /dev/null 2>&1
```

#### 宝塔面板

1. 登录宝塔 → 计划任务
2. 任务类型选择「访问 URL」
3. 执行周期：每天 02:00
4. URL 地址填写 Cron URL

#### 其他面板 / 云函数

使用任意 HTTP 请求工具（curl / wget / Python 等）定时访问 Cron URL 即可。

### 9.4 手动触发更新

在后台管理面板点击「🔄 更新所有信息」按钮，即可手动执行全量更新。

> **注意**：全量更新可能耗时较长（取决于链接数量和网络状况），建议使用 Cron 在服务器空闲时段执行。

---

## 十、缓存机制

插件采用**两级缓存**策略，兼顾性能与实时性：

### 10.1 数据缓存（JSON）

- **文件**：`cache/friendlinks.cache.json`
- **内容**：所有 `status=1` 的链接数据
- **触发重建**：
  - 添加/编辑/删除链接后自动刷新
  - 点击后台「刷新缓存」按钮
  - 手动调用 `FriendLinks_Plugin::refreshCache()`
- **作用**：避免每次前台访问都查询数据库

### 10.2 渲染缓存（HTML）

- **文件**：`cache/friendlinks_rendered_*.html`
- **条件**：仅在**默认参数**（不指定分类、不指定未分类模式、不强制包含异常）时启用
- **有效期**：由插件配置中的「缓存时间（秒）」控制，默认 7 天
- **触发重建**：过期后首次访问自动重建
- **作用**：避免每次前台访问都执行模板解析和字符串替换

### 10.3 缓存清理

以下操作会自动清空全部缓存：
- 后台点击「刷新缓存」
- 添加/编辑/删除链接（数据缓存重建，渲染缓存清空）
- 调用 `FriendLinks_Plugin::refreshCache()`

---

## 十一、常见问题

### Q1：添加链接后标题/描述/图标没有自动抓取？

- 检查目标网站是否可正常访问
- 检查服务器是否允许对外 HTTP 请求（防火墙 / 安全组）
- 在插件配置中适当增加「请求超时」时间
- 部分网站有反爬虫机制，可能需要手动填写

### Q2：前台显示「暂无友情链接」？

- 检查后台是否已添加链接
- 检查链接状态是否为「显示」（隐藏状态的链接不会输出）
- 检查是否使用了分类过滤，但目标分类下无链接
- 检查缓存是否已过期，尝试后台「刷新缓存」

### Q3：图标显示为默认图标或空白？

- 插件会依次尝试：HTML 内 `<link rel="icon">` 标签 → 根目录 `/favicon.ico` 等常见路径
- 如果目标网站图标路径非常规，建议手动填写「图标 URL」
- 确保「默认图标 URL」配置项指向一个有效的图片地址

### Q4：如何修改前台链接的排列顺序？

**方式一**：后台手动排序
- 在链接列表中编辑「排序」字段，数字越小越靠前
- 点击「🔢 重整序号」可自动按当前列表顺序重新编号

**方式二**：插件配置默认排序
- 在插件配置中选择「前台排序方式」

**方式三**：允许访客自选（需开启配置）
- 开启「允许访客选择排序」后，前台会出现下拉框

### Q5：定时任务返回 403 / Invalid key？

- 检查 URL 中的 `key` 参数是否与插件配置中的「Cron 密钥」一致
- 如果设置了密钥，URL 必须包含 `?key=YOUR_SECRET_KEY`
- 如果未设置密钥，确保 URL 不包含 `key` 参数或参数为空

### Q6：禁用插件后数据会丢失吗？

- 默认情况下**不会**删除数据表，重新启用后数据仍在
- 如果在插件配置中开启了「禁用插件时删除数据表」，禁用后会**永久删除**所有数据

### Q7：如何备份友情链接数据？

直接备份数据库中的以下两张表即可：
- `{prefix}friendlinks`
- `{prefix}friendlinks_categories`

---

## 十二、卸载与数据清理

### 12.1 仅卸载插件（保留数据）

1. 后台 → 控制台 → 插件 → 禁用 FriendLinks
2. 数据表和链接记录会保留，重新启用后恢复使用

### 12.2 彻底卸载（删除数据）

1. 进入插件配置页面
2. 将「禁用插件时删除数据表」设置为「**删除**」
3. 保存配置
4. 后台 → 控制台 → 插件 → 禁用 FriendPlugins
5. 删除 `/usr/plugins/FriendLinks/` 目录

> ⚠️ **警告**：此操作不可恢复，请提前备份数据库！

---

## 附录：更新日志

### v2.3.0
- 新增分类管理功能
- 新增容器模板支持（`{container_class}` / `{cards}` 占位符）
- 新增渲染级 HTML 缓存，大幅提升前台性能
- 新增访客排序选择功能（Cookie 记忆偏好）
- 新增异常链接批量删除功能
- 优化并发抓取逻辑，提升批量更新效率
- 优化后台管理面板交互（AJAX 无刷新分页/筛选/排序）

---

*本文档最后更新于 2026-05-31*
