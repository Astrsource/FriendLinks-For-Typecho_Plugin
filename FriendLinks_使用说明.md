# FriendLinks 友情链接插件使用说明

> **适用版本**: Typecho 1.2+  
> **插件版本**: 2.2.4  
> **作者**: Astrsource  
> **官网**: https://astrsource.com

---

## 目录

1. [插件简介](#一插件简介)
2. [安装与启用](#二安装与启用)
3. [插件配置详解](#三插件配置详解)
4. [后台管理面板](#四后台管理面板)
5. [分类管理](#五分类管理)
6. [前台展示方式](#六前台展示方式)
7. [自动抓取与存活检测](#七自动抓取与存活检测)
8. [缓存机制](#八缓存机制)
9. [定时任务（Cron）](#九定时任务cron)
10. [模板与CSS自定义](#十模板与css自定义)
11. [常见问题](#十一常见问题)

---

## 一、插件简介

FriendLinks 是一款为 Typecho 1.2+ 开发的高性能友情链接插件，支持自动抓取网站标题、描述和图标，提供分类管理、存活状态检测、缓存机制、定时更新等完整功能。

### 核心特性

| 特性 | 说明 |
|------|------|
| **自动抓取** | 自动获取友链网站的标题、描述、Favicon 图标 |
| **分类管理** | 支持无限分类，链接可归入不同分类或保持未分类 |
| **存活检测** | 自动检测链接是否可访问，标记异常网站 |
| **并发更新** | 使用 `curl_multi` 并发抓取，批量更新更高效 |
| **多级缓存** | 数据缓存（JSON）+ 渲染缓存（HTML），支持自定义过期时间 |
| **访客排序** | 允许前台访客切换排序方式，偏好自动保存 |
| **短代码支持** | 文章/页面中插入 `[friendlinks]` 短代码即可展示 |
| **模板函数** | 支持在主题模板中直接调用 `FriendLinks_Plugin::output()` |
| **定时任务** | 提供 Cron 接口，可配合服务器定时任务自动更新 |
| **编码兼容** | 自动识别 GBK/UTF-8 编码，正确处理中文网站 |

---

## 二、安装与启用

### 2.1 环境要求

- **Typecho**: 1.2.0 或更高版本
- **PHP**: 7.4+（需启用 **cURL 扩展**）
- **权限**: 插件目录可写（用于创建缓存文件）

### 2.2 安装步骤

1. 下载插件压缩包，解压后得到 `FriendLinks` 文件夹
2. 将文件夹上传至 Typecho 的插件目录：`/usr/plugins/`
3. 确保目录结构如下：
   ```
   /usr/plugins/FriendLinks/
   ├── Plugin.php      # 主插件文件
   ├── Action.php      # Ajax 接口与定时任务
   ├── panel.php       # 后台管理面板
   └── ...
   ```
4. 登录 Typecho 后台 → 控制台 → 插件
5. 找到 **FriendLinks**，点击**启用**

### 2.3 首次启用

启用插件后，系统会自动：
- 创建数据表 `{prefix}friendlinks`（存储链接）
- 创建数据表 `{prefix}friendlinks_categories`（存储分类）
- 创建缓存目录 `/usr/cache/`（若不存在）
- 在后台侧边栏「管理」菜单下添加「友情链接」入口

> ⚠️ **注意**: 若启用失败并提示 "需要 PHP cURL 扩展"，请先安装或启用 cURL 扩展。

---

## 三、插件配置详解

启用插件后，点击插件名称旁的「设置」进入配置页面。

### 3.1 基础配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| **缓存时间（秒）** | `604800` | 渲染缓存有效期，默认 7 天（604800 秒） |
| **请求超时（秒）** | `10` | 抓取网站信息时的 cURL 超时时间 |
| **默认图标 URL** | `/favicon.png` | 当网站无法获取图标时显示的默认图标，留空则不显示 |

### 3.2 前台展示配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| **前台排序方式** | 手动排序 | 前台默认的链接排列顺序 |
| **允许访客选择排序** | 关闭 | 开启后前台显示排序下拉框，访客可切换排序 |
| **跳过异常网站** | 不跳过 | 前台是否隐藏存活状态为「异常」的链接 |

**可选排序方式**：
- `manual` — 手动排序（按后台设置的 `sort` 值）
- `created_desc` — 添加时间（新→旧）
- `created_asc` — 添加时间（旧→新）
- `title_asc` — 标题 A→Z
- `title_desc` — 标题 Z→A
- `random` — 随机排序

### 3.3 高级配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| **Cron 密钥** | 空 | 定时任务访问的验证密钥，建议设置复杂随机字符串 |
| **禁用插件时删除数据表** | 不删除 | ⚠️ **危险选项**：选择删除后，禁用插件将永久清空所有链接和分类数据 |

### 3.4 卡片模板

用于定义前台每个友链卡片的 HTML 结构。支持以下占位符：

| 占位符 | 说明 |
|--------|------|
| `{url}` | 网站地址 |
| `{title}` | 网站标题 |
| `{description}` | 网站描述 |
| `{icon}` | 图标 URL |
| `{last_update}` | 最后更新时间（格式：Y-m-d） |
| `{alive}` | 存活状态（正常/异常/未知） |
| `{category}` | 所属分类名称 |

> 💡 **提示**: 模板根类名为 `.friendlink-card`，若通过 `card_class` 参数传入自定义类名，会自动追加为 `class="friendlink-card my-card"`。

### 3.5 自定义 CSS

用于编写友链卡片的样式。默认 CSS 已包含完整的卡片布局、Favicon 区域、描述区域、Badge 组样式及响应式适配。

---

## 四、后台管理面板

登录后台 → 管理 → 友情链接，进入管理面板。

### 4.1 面板布局

面板分为以下几个区域：

1. **分类管理区** — 卡片式展示所有分类及链接数量
2. **缓存状态区** — 显示缓存文件是否存在、大小、最后更新时间、剩余有效期
3. **工具栏** — 快捷操作按钮 + 分类筛选 + 排序选择
4. **链接列表** — 分页展示所有链接
5. **使用说明区** — 短代码与模板调用示例

### 4.2 工具栏操作

| 按钮 | 功能 |
|------|------|
| ➕ 添加链接 | 打开模态框添加新友链 |
| 🔄 更新所有信息 | 重新抓取所有**显示状态**链接的网站信息 |
| 🗑️ 刷新缓存 | 立即从数据库重建缓存并清空渲染缓存 |
| 🔢 重整序号 | 将所有链接的 `sort` 值从 1 开始连续重排 |
| 🗑️ 删除异常链接 | 一键删除所有存活状态为「异常」的链接（不可恢复） |

### 4.3 筛选与排序

- **分类筛选**: 全部 / 未分类 / 异常 / 具体分类
- **排序方式**: 手动排序 / 添加时间 ↓↑ / 标题 A-Z / Z-A / 随机
- **分页**: 支持每页 10/20/50 条切换

### 4.4 链接列表字段

| 字段 | 说明 |
|------|------|
| ID | 链接唯一标识 |
| 存活 | 正常（绿色）/ 异常（红色）/ 未知（灰色） |
| 分类 | 所属分类标签 |
| 标题 | 网站标题 |
| 描述 | 网站描述（前 30 字） |
| URL | 可点击跳转的链接地址 |
| 图标 | 24×24 预览图 |
| 状态 | 显示（绿色）/ 隐藏（红色） |
| 排序 | 手动排序值（越小越靠前） |
| 最后更新 | 信息最后抓取时间 |
| 操作 | 编辑 / 更新信息 / 删除 |

---

## 五、分类管理

### 5.1 添加分类

1. 在分类管理区点击「➕ 添加分类」
2. 填写分类名称（如：友链、合作伙伴、技术博客）
3. 排序值留空则自动递增（取当前最大值 +1）
4. 点击保存

### 5.2 编辑分类

点击分类卡片上的「✏️ 编辑」按钮，可修改分类名称和排序值。

### 5.3 删除分类

点击「🗑️ 删除」按钮，确认后该分类将被删除，**其下所有链接自动移至「未分类」**。

### 5.4 未分类链接

未设置分类的链接统一归入「未分类」。在分类筛选或短代码参数中可通过 `uncategorized` 进行筛选。

---

## 六、前台展示方式

### 6.1 方式一：短代码（推荐）

在文章或独立页面中插入以下短代码：

```markdown
<!-- 基础用法：展示全部链接 -->
[friendlinks]

<!-- 指定分类ID（此时忽略未分类参数） -->
[friendlinks category_id="1"]

<!-- 自定义 CSS 类名 -->
[friendlinks container_class="my-links" card_class="my-card"]

<!-- 包含异常链接（强制显示） -->
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
| `container_class` | string | `friendlinks-container` | 外层容器自定义类名 |
| `card_class` | string | 空 | 每个卡片追加的自定义类名 |
| `category_id` | int | 空 | 按分类 ID 过滤，指定后忽略 `include_uncategorized` |
| `include_uncategorized` | int/string | `1` | `1`=全部(默认), `0`=排除未分类, `2`=仅未分类 |
| `include_dead` | int | `0` | `0`=按全局配置, `1`=强制包含异常, `2`=仅异常 |

### 6.2 方式二：模板函数

在主题模板文件（如 `page-links.php`）中直接调用：

```php
<?php
// 默认输出全部链接
FriendLinks_Plugin::output();

// 仅输出有分类的链接（排除未分类）
FriendLinks_Plugin::output('friendlinks-container', '', null, 0);

// 仅输出未分类链接
FriendLinks_Plugin::output('friendlinks-container', '', null, 2);

// 仅输出分类ID为1的链接
FriendLinks_Plugin::output('friendlinks-container', '', 1);

// 完整参数
// output($containerClass, $cardClass, $categoryId, $uncategorizedMode, $includeDeadMode);
?>
```

#### 模板函数参数说明

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `$containerClass` | string | `friendlinks-container` | 外层容器类名 |
| `$cardClass` | string | 空 | 卡片追加类名 |
| `$categoryId` | int/null | `null` | 指定分类 ID |
| `$uncategorizedMode` | int | `1` | `1`=全部, `0`=排除未分类, `2`=仅未分类 |
| `$includeDeadMode` | int | `0` | `0`=按全局配置, `1`=强制包含, `2`=仅异常 |

---

## 七、自动抓取与存活检测

### 7.1 自动抓取流程

添加或更新链接时，插件会自动：

1. **访问目标网站** — 使用 cURL 请求（支持 301/302 重定向，最多 5 次）
2. **编码转换** — 自动识别 `Content-Type` 头部或 HTML `<meta charset>` 标签，将 GBK/BIG5 等编码转换为 UTF-8
3. **提取标题** — 优先级：`og:title` > `twitter:title` > `<title>` 标签
4. **提取描述** — 优先级：`og:description` > `twitter:description` > `meta name="description"`
5. **提取图标** — 解析 HTML 中的 `<link rel="icon">` 标签，支持相对路径自动补全
6. **根目录探测** — 若 HTML 中未找到图标，依次探测 `/favicon.ico`、 `/favicon.png`、 `/favicon.svg`、 `/icon.png`、 `/apple-touch-icon.png` 等常见路径
7. **存活检测** — HTTP 状态码 200-399 视为正常，否则标记异常

### 7.2 批量更新

后台点击「🔄 更新所有信息」或使用定时任务时，插件会使用 **curl_multi 并发请求**，同时抓取所有显示状态链接的信息，大幅缩短更新时间。

### 7.3 存活状态说明

| 状态 | 含义 | 前台表现 |
|------|------|----------|
| **正常** | HTTP 200-399，网站可访问 | 绿色 Badge，默认显示 |
| **异常** | HTTP 4xx/5xx 或请求失败 | 红色 Badge，默认隐藏（取决于全局设置） |
| **未知** | 尚未执行过存活检测 | 灰色 Badge |

---

## 八、缓存机制

插件采用**双级缓存**策略，兼顾性能与实时性。

### 8.1 数据缓存（JSON）

- **文件**: `/usr/cache/friendlinks.cache.json`
- **内容**: 所有 `status=1`（显示状态）的链接数据
- **触发重建**: 添加/编辑/删除链接、手动点击「刷新缓存」、定时任务执行后
- **用途**: 前台渲染时直接读取，避免频繁查询数据库

### 8.2 渲染缓存（HTML）

- **文件**: `/usr/cache/friendlinks_rendered_{hash}.html`
- **内容**: 完整渲染后的 HTML（含 CSS、排序选择器、卡片列表）
- **触发重建**: 数据缓存更新时自动清空、配置项变更
- **条件**: 仅在**无分类过滤**、**无特殊参数**、**默认包含未分类和存活链接**时启用
- **用途**: 完全相同的展示场景直接输出静态 HTML，零数据库查询

### 8.3 缓存状态查看

后台面板「缓存状态」区域实时显示：
- 缓存文件是否存在
- 文件大小（KB）
- 最后更新时间
- 剩余有效时间（小时/分钟）
- 是否已过期

---

## 九、定时任务（Cron）

插件提供独立的 Cron 接口，用于服务器定时自动更新所有链接信息。

### 9.1 Cron URL

```
https://你的域名/friendlinks/cron
```

若设置了 **Cron 密钥**，URL 需追加参数：

```
https://你的域名/friendlinks/cron?key=你的密钥
```

### 9.2 设置方法

#### Linux / Mac（crontab）

编辑 crontab：

```bash
crontab -e
```

添加以下行（每天凌晨 2 点执行）：

```bash
0 2 * * * curl -s "https://你的域名/friendlinks/cron?key=你的密钥" > /dev/null 2>&1
```

#### 宝塔面板

1. 进入「计划任务」
2. 任务类型选择「访问 URL」
3. 执行周期：每天 2:00
4. URL 地址：`https://你的域名/friendlinks/cron?key=你的密钥`

#### cPanel

1. 进入「Cron Jobs」
2. 设置执行频率（如 Once Per Day）
3. 命令填写：
   ```bash
   /usr/bin/curl -s "https://你的域名/friendlinks/cron?key=你的密钥"
   ```

### 9.3 安全提示

- **强烈建议设置 Cron 密钥**，防止接口被恶意调用
- 密钥建议使用 16 位以上随机字符串（如 `aB3#kL9$vQ2@wE5!`）
- 若未设置密钥，任何人均可访问该接口触发更新

---

## 十、模板与CSS自定义

### 10.1 修改卡片模板

进入插件设置 → 卡片模板，修改 HTML 结构。例如：

```html
<div class="friendlink-card">
    <div class="result-header">
        <div class="favicon">
            <img src="{icon}" alt="favicon">
        </div>
        <div>
            <div class="title">{title}</div>
            <div class="url-display">
                <a href="{url}" target="_blank">{url}</a>
            </div>
        </div>
    </div>
    <div class="description">
        <div class="label">描述</div>
        {description}
    </div>
    <div class="badge-group">
        <span class="badge badge-category">{category}</span>
        <span class="badge badge-update">{last_update}</span>
        <span class="badge badge-status">{alive}</span>
    </div>
</div>
```

### 10.2 修改卡片样式

进入插件设置 → 自定义 CSS，编写覆盖样式。例如：

```css
/* 修改卡片背景为深色主题 */
.friendlink-card {
    background: #1e293b;
    border-color: #334155;
    color: #e2e8f0;
}

/* 修改标题颜色 */
.friendlink-card .title {
    color: #38bdf8;
}
```

### 10.3 使用自定义类名

通过短代码或模板函数追加自定义类名，实现不同页面的差异化样式：

```markdown
[friendlinks container_class="dark-theme-links" card_class="dark-card"]
```

对应 CSS：

```css
.dark-theme-links .dark-card {
    background: #0f172a;
}
```

---

## 十一、常见问题

### Q1: 启用插件提示 "需要 PHP cURL 扩展"
**A**: 你的 PHP 环境未安装 cURL 扩展。在 `php.ini` 中取消注释 `extension=curl`（Linux 通常需安装 `php-curl` 包），重启 Web 服务器后重试。

### Q2: 添加链接后标题/描述显示乱码
**A**: 插件已内置编码自动检测（Content-Type 头部和 meta charset）。若仍乱码，可能是目标网站编码声明不规范。可尝试手动编辑链接补充正确信息。

### Q3: 图标无法抓取或显示为默认图标
**A**: 
1. 检查目标网站是否有 `/favicon.ico`
2. 部分网站使用 `.svg` 或数据 URI 作为图标，可能无法被常规方式获取
3. 可在后台手动编辑链接，填入图标 URL
4. 确保「默认图标 URL」配置项有效

### Q4: 前台不显示某些链接
**A**: 
- 检查链接「状态」是否为「显示」
- 检查「跳过异常网站」是否开启，异常链接会被隐藏
- 检查是否使用了 `category_id` 或 `include_uncategorized` 参数过滤

### Q5: 缓存不更新怎么办？
**A**: 
1. 后台点击「🗑️ 刷新缓存」强制重建
2. 检查 `/usr/cache/` 目录是否有写入权限（建议 755）
3. 缩短「缓存时间」配置值

### Q6: 定时任务返回 "Invalid key"
**A**: URL 中的 `key` 参数与插件设置中的「Cron 密钥」不一致。请核对密钥是否包含特殊字符（如 `&`、`?`），若有请在 URL 中正确编码。

### Q7: 禁用插件后数据会丢失吗？
**A**: 默认**不会**。只有在插件设置中显式选择「禁用插件时删除数据表 → 删除」，禁用后才会清空数据。建议定期备份数据库。

### Q8: 如何备份友链数据？
**A**: 直接备份 Typecho 数据库中的以下两张表即可：
- `{prefix}friendlinks`
- `{prefix}friendlinks_categories`

---

## 附录：数据库表结构

### friendlinks（链接表）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int(11) | 主键，自增 |
| `url` | varchar(255) | 网站地址 |
| `title` | varchar(255) | 网站标题 |
| `description` | text | 网站描述 |
| `icon` | varchar(255) | 图标 URL |
| `status` | tinyint(1) | 1=显示, 0=隐藏 |
| `sort` | int(11) | 排序值 |
| `category_id` | int(11) | 分类 ID（NULL=未分类） |
| `last_update` | int(11) | 最后更新时间戳 |
| `created` | int(11) | 创建时间戳 |
| `alive` | tinyint(1) | 1=正常, 0=异常, NULL=未知 |
| `alive_checked` | int(11) | 存活检测时间戳 |

### friendlinks_categories（分类表）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int(11) | 主键，自增 |
| `name` | varchar(100) | 分类名称 |
| `sort` | int(11) | 排序值 |
| `created` | int(11) | 创建时间戳 |

---

*文档版本：v2.2.4 | 最后更新：2026-05-15*
