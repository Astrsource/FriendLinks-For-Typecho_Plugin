<?php
/**
 * 友情链接插件 - 自动抓取网站信息版
 * <br>
 * 启用后在 [管理] -> [友情链接] 管理友情链接
 *
 * @package FriendLinks
 * @author Astrsource
 * @version 2.3.0
 * @link https://astrsource.com
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;

class FriendLinks_Plugin implements PluginInterface
{
    const TABLE_NAME = 'friendlinks';
    const CATEGORY_TABLE = 'friendlinks_categories';

    /**
     * 缓存目录（插件目录下 cache 子目录）
     */
    const CACHE_DIR = __DIR__ . '/cache/';

    private static $pluginOptionsCache = null;
    private static $linksCache = null;

    /**
     * 激活插件
     */
    public static function activate()
    {
        if (!extension_loaded('curl')) {
            throw new \Typecho\Plugin\Exception(_t('需要 PHP cURL 扩展'));
        }

        self::createTables();

        // 确保缓存目录存在
        if (!is_dir(self::CACHE_DIR)) {
            if (!mkdir(self::CACHE_DIR, 0755, true)) {
                throw new \Typecho\Plugin\Exception(_t('无法创建缓存目录：' . self::CACHE_DIR));
            }
        }

        Helper::addPanel(3, 'FriendLinks/panel.php', _t('友情链接'), _t('管理友情链接'), 'administrator');
        Helper::addAction('friendlinks-update', 'FriendLinks_Action');
        Helper::addRoute('friendlinks_cron', '/friendlinks/cron', 'FriendLinks_Action', 'cron');
        \Typecho\Plugin::factory('Widget\Abstract\Contents')->contentEx = ['FriendLinks_Plugin', 'parseShortcode'];
        \Typecho\Plugin::factory('Widget\Abstract\Contents')->excerptEx = ['FriendLinks_Plugin', 'parseShortcode'];

        return _t('自动抓取网站信息，支持缓存、定时更新、分类管理、自定义容器模板和 CSS');
    }

    /**
     * 停用插件
     */
    public static function deactivate()
    {
        $pluginOptions = self::getPluginOptions();
        $dropTable = isset($pluginOptions->dropTableOnDeactivate) && $pluginOptions->dropTableOnDeactivate == 1;
        if ($dropTable) {
            $db = Db::get();
            $prefix = $db->getPrefix();
            try {
                $db->query("DROP TABLE IF EXISTS `{$prefix}" . self::TABLE_NAME . "`");
                $db->query("DROP TABLE IF EXISTS `{$prefix}" . self::CATEGORY_TABLE . "`");
            } catch (Exception $e) {}
        }
        Helper::removeAction('FriendLinks-edit');
        Helper::removePanel(3, 'FriendLinks/panel.php');
    }

    /**
     * 获取插件选项（带内存缓存）
     */
    private static function getPluginOptions()
    {
        if (self::$pluginOptionsCache === null) {
            self::$pluginOptionsCache = Helper::options()->plugin('FriendLinks');
        }
        return self::$pluginOptionsCache;
    }

    /**
     * 清空选项缓存（留作备用）
     */
    private static function clearPluginOptionsCache()
    {
        self::$pluginOptionsCache = null;
    }

    /**
     * 插件配置面板
     */
    public static function config(Form $form)
    {
        // 缓存时间
        $cacheTime = new \Typecho\Widget\Helper\Form\Element\Text(
            'cacheTime', null, '604800',
            _t('缓存时间（秒）'),
            _t('缓存时间单位为秒，默认 7 天；渲染缓存过期后自动重建')
        );
        $form->addInput($cacheTime);

        // 请求超时
        $timeout = new \Typecho\Widget\Helper\Form\Element\Text(
            'timeout', null, '10',
            _t('请求超时（秒）'),
            _t('请求超时时间单位为秒，默认 10 秒')
        );
        $form->addInput($timeout);

        // 默认图标
        $defaultIcon = new \Typecho\Widget\Helper\Form\Element\Text(
            'defaultIcon', null, '/favicon.png',
            _t('默认图标 URL'),
            _t('当无法获取到网站图标时显示的默认图标地址，留空则不显示')
        );
        $form->addInput($defaultIcon);

        // 容器模板
        $containerTemplate = new \Typecho\Widget\Helper\Form\Element\Textarea(
            'containerTemplate', null,
            '<div class="{container_class}">{cards}</div>',
            _t('容器模板'),
            _t('外部容器 HTML。占位符：<code>{cards}</code> – 卡片列表，<code>{container_class}</code> – 容器 class（由短代码或模板函数传入，默认 friendlinks-container）')
        );
        $form->addInput($containerTemplate);
        // ==================================

        // 卡片模板
        $template = new \Typecho\Widget\Helper\Form\Element\Textarea(
            'template', null,
            '<div class="friendlink-card">
        <!-- 头部 -->
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

        <!-- 描述 -->
        <div class="description">
            <div class="label">描述</div>
            {description}
        </div>

        <!-- 底部 Badge 组 -->
        <div class="badge-group">
            <span class="badge badge-category">{category}</span>
            <span class="badge badge-update">{last_update}</span>
            <span class="badge badge-status">{alive}</span>
        </div>
    </div>',
            _t('卡片模板'),
            _t('占位符：{url}、{title}、{description}、{icon}、{last_update}、{alive}、{category}<br>基类 <code>.friendlink-card</code>，通过 <code>card_class</code> 参数可追加自定义类名')
        );
        $form->addInput($template);

        // 自定义 CSS
        $customCss = new \Typecho\Widget\Helper\Form\Element\Textarea(
            'customCss', null,
            '.friendlink-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            max-width: 520px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .result-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .favicon {
            width: 48px;
            height: 48px;
            background: #f1f5f9;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            flex-shrink: 0;
        }

        .favicon img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .title {
            font-size: 20px;
            font-weight: 600;
            color: #0f172a;
            word-break: break-word;
        }

        .url-display {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
            word-break: break-all;
        }

        .url-display a {
            color: #2563eb;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .url-display a:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .description {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed #cbd5e1;
            color: #334155;
            line-height: 1.5;
            word-break: break-word;
        }

        .description .label {
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        /* ========== 底部 Badge 区域 ========== */
        .badge-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed #cbd5e1;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-family: "SF Mono", "Fira Code", "JetBrains Mono", "Consolas", monospace;
            font-size: 13px;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 20px;
            line-height: 1;
            white-space: nowrap;
            letter-spacing: 0.3px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            cursor: default;
        }

        .badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .badge-category {
            background: #ede9fe;
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }
        .badge-category::before {
            content: "📁";
            font-size: 11px;
        }

        .badge-update {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }
        .badge-update::before {
            content: "📅";
            font-size: 11px;
        }

        .badge-status {
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .badge-status::before {
            content: "✅";
            font-size: 11px;
        }

        .error .badge-status {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .error .badge-status::before {
            content: "❌";
        }

        .warning .badge-status {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fed7aa;
        }
        .warning .badge-status::before {
            content: "⚠️";
        }

        @media (max-width: 400px) {
            .badge-group {
                gap: 8px;
            }
            .badge {
                font-size: 12px;
                padding: 5px 10px;
                border-radius: 16px;
            }
        }',
            _t('自定义 CSS'),
            _t('自定义友情链接卡片的 CSS 样式')
        );
        $form->addInput($customCss);

        // 前台排序方式
        $sortOrder = new \Typecho\Widget\Helper\Form\Element\Select(
            'sortOrder',
            [
                'manual'       => _t('手动排序'),
                'created_desc' => _t('添加时间（新→旧）'),
                'created_asc'  => _t('添加时间（旧→新）'),
                'title_asc'    => _t('标题 A→Z'),
                'title_desc'   => _t('标题 Z→A'),
                'random'       => _t('随机')
            ],
            'manual',
            _t('前台排序方式'),
            _t('选择友情链接在前台页面中的显示顺序<br>若开启“允许访客选择排序”，此设置将被覆盖')
        );
        $form->addInput($sortOrder);

        // 允许访客选择排序
        $allowVisitorSort = new \Typecho\Widget\Helper\Form\Element\Radio(
            'allowVisitorSort',
            ['0' => _t('关闭'), '1' => _t('开启')],
            '0',
            _t('允许访客选择排序'),
            _t('开启后前台显示排序下拉框，访客可切换排序方式，偏好记录在浏览器中')
        );
        $form->addInput($allowVisitorSort);

        // 跳过异常网站
        $skipDeadLinks = new \Typecho\Widget\Helper\Form\Element\Radio(
            'skipDeadLinks',
            ['0' => _t('不跳过'), '1' => _t('跳过')],
            '0',
            _t('跳过异常网站'),
            _t('前台渲染时，是否跳过存活状态为异常的链接（短代码参数 include_dead 可覆盖此设置）')
        );
        $form->addInput($skipDeadLinks);

        // Cron 密钥
        $secretKey = new \Typecho\Widget\Helper\Form\Element\Text(
            'secretKey', null, '',
            _t('Cron 密钥'),
            _t('设置服务器 Cron 定时访问时的密钥，用于验证请求来源')
        );
        $form->addInput($secretKey);

        // 禁用时删除数据表
        $dropTable = new \Typecho\Widget\Helper\Form\Element\Radio(
            'dropTableOnDeactivate',
            [
                '0' => _t('不删除'),
                '1' => _t('<span style="color: red;font-weight: bold;">删除</span>')
            ],
            '0',
            _t('禁用插件时删除数据表'),
            _t('<span style="color: red;font-weight: bold;">警告：</span>选择删除后，禁用插件将永久删除所有链接和分类数据')
        );
        $form->addInput($dropTable);
    }

    public static function personalConfig(Form $form) {}

    /**
     * 创建数据库表
     */
    private static function createTables()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        $categoryTable = $prefix . self::CATEGORY_TABLE;
        $db->query("CREATE TABLE IF NOT EXISTS `{$categoryTable}` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `sort` int(11) DEFAULT '0',
            `created` int(11) DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_sort` (`sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $table = $prefix . self::TABLE_NAME;
        $db->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `url` varchar(255) NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text,
            `icon` varchar(255) DEFAULT NULL,
            `status` tinyint(1) DEFAULT '1',
            `sort` int(11) DEFAULT '0',
            `category_id` int(11) DEFAULT NULL,
            `last_update` int(11) DEFAULT '0',
            `created` int(11) DEFAULT '0',
            `alive` tinyint(1) DEFAULT NULL,
            `alive_checked` int(11) DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_status_sort` (`status`, `sort`),
            KEY `idx_category_id` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 兼容旧版：确保含有 category_id 字段
        try {
            $result = $db->fetchAll($db->query("SHOW COLUMNS FROM `{$table}` LIKE 'category_id'"));
            if (empty($result)) {
                $db->query("ALTER TABLE `{$table}` ADD COLUMN `category_id` int(11) DEFAULT NULL AFTER `sort`, ADD KEY `idx_category_id` (`category_id`)");
            }
        } catch (Exception $e) {}
    }

    /**
     * 短代码解析（支持无序参数）
     */
    public static function parseShortcode($content, $widget, $lastResult)
    {
        $content = empty($lastResult) ? $content : $lastResult;
        if (strpos($content, '[friendlinks') !== false) {
            $pattern = '/\[friendlinks\s*(.*?)\]/i';
            $content = preg_replace_callback($pattern, function ($m) {
                $attrs = self::parseShortcodeAttrs(trim($m[1]));
                return self::renderLinks(
                    $attrs['container_class'] ?? 'friendlinks-container',
                    $attrs['card_class'] ?? '',
                    isset($attrs['category_id']) ? (int) $attrs['category_id'] : null,
                    self::resolveUncategorizedMode($attrs['include_uncategorized'] ?? '1'),
                    isset($attrs['include_dead']) ? (int) $attrs['include_dead'] : 0
                );
            }, $content);
        }
        return $content;
    }

    /**
     * 解析短代码中的属性（名="值" 格式）
     */
    private static function parseShortcodeAttrs(string $str): array
    {
        $attrs = [];
        if (preg_match_all('/([a-z_]+)="([^"]*)"/i', $str, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $attrs[$pair[1]] = $pair[2];
            }
        }
        return $attrs;
    }

    /**
     * 转换未分类模式参数值
     * @param string $raw
     * @return int 0,1,2
     */
    private static function resolveUncategorizedMode(string $raw): int
    {
        $raw = strtolower($raw);
        if ($raw === '0' || $raw === 'false') {
            return 0;
        } elseif ($raw === '2') {
            return 2;
        }
        return 1;
    }

    /**
     * 核心渲染方法（增加容器模板支持）
     */
    public static function renderLinks(
        $containerClass = 'friendlinks-container',
        $cardClass = '',
        $categoryId = null,
        $uncategorizedMode = 1,
        $includeDeadMode = 0
    ) {
        $options = self::getPluginOptions();
        $cacheTime      = (int) ($options->cacheTime ?? 604800);
        $template       = $options->template ?: '<div class="friendlink-card">...</div>';
        $customCss      = $options->customCss ?? '';
        $defaultSort    = $options->sortOrder ?? 'manual';
        $defaultIcon    = $options->defaultIcon ?? '';
        $allowVisitor   = !empty($options->allowVisitorSort);
        $globalSkipDead = ($options->skipDeadLinks ?? '0') == '1';

        // 容器模板
        $containerTemplate = $options->containerTemplate ?? '<div class="{container_class}">{cards}</div>';
        if (strpos($containerTemplate, '{cards}') === false) {
            $containerTemplate = '<div class="{container_class}">{cards}</div>';
        }

        // 当前排序方式
        $allowedSorts = ['manual', 'created_desc', 'created_asc', 'title_asc', 'title_desc', 'random'];
        $currentSort = $defaultSort;
        if ($allowVisitor) {
            $sortKey = 'fl_sort_' . md5($containerClass . '|' . ($categoryId ?? '0') . '|' . $uncategorizedMode . '|' . $includeDeadMode);
            $cookieSort = $_COOKIE[$sortKey] ?? '';
            if ($cookieSort && in_array($cookieSort, $allowedSorts)) {
                $currentSort = $cookieSort;
            } elseif (isset($_GET['sort']) && in_array($_GET['sort'], $allowedSorts)) {
                $currentSort = $_GET['sort'];
            }
        }

        // 渲染级缓存策略（仅当默认条件时使用）
        $useRenderCache = ($categoryId === null) && ($uncategorizedMode === 1) && ($includeDeadMode === 0);
        if ($useRenderCache) {
            $renderKey = 'friendlinks_rendered_' . md5($containerClass . $cardClass . $template . $customCss . $currentSort . $defaultIcon . ($globalSkipDead ? '1' : '0'));
            $cacheFile = self::CACHE_DIR . $renderKey . '.html';
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
                return file_get_contents($cacheFile);
            }
        }

        // 从缓存加载数据
        $links = self::getLinksFromCacheOnly();
        if (empty($links)) {
            return '<p class="friendlinks-empty">' . _t('暂无友情链接') . '</p>';
        }

        // 分类过滤
        if ($categoryId !== null) {
            $links = array_values(array_filter($links, function ($link) use ($categoryId) {
                return ($link['category_id'] ?? null) == $categoryId;
            }));
        } else {
            $links = array_values(array_filter($links, function ($link) use ($uncategorizedMode) {
                if ($uncategorizedMode === 0) {
                    return !empty($link['category_id']);
                } elseif ($uncategorizedMode === 2) {
                    return empty($link['category_id']);
                }
                return true;
            }));
        }

        // 存活状态过滤
        if ($includeDeadMode === 2) {
            $links = array_values(array_filter($links, function ($link) {
                return isset($link['alive']) && $link['alive'] == 0;
            }));
        } elseif ($includeDeadMode !== 1 && $globalSkipDead) {
            $links = array_values(array_filter($links, function ($link) {
                return ($link['alive'] ?? null) !== 0;
            }));
        }

        if (empty($links)) {
            return '<p class="friendlinks-empty">' . _t('该条件下暂无链接') . '</p>';
        }

        // 排序（若使用整体缓存且随机，则客户端打乱）
        if ($useRenderCache && $currentSort === 'random') {
            // 留在后面处理
        } else {
            self::sortLinks($links, $currentSort);
        }

        // 分类映射
        $catNames = [];
        foreach (self::getCategories() as $cat) {
            $catNames[$cat['id']] = $cat['name'];
        }

        // 访客排序选择器
        $sortSelectorHtml = '';
        if ($allowVisitor) {
            $sortSelectorHtml = self::buildSortSelector($currentSort, $sortKey);
        }

        // 构建卡片 HTML
        $cardsHtml = '';
        foreach ($links as $link) {
            $icon = $link['icon'] ?: $defaultIcon;
            $lastUpdate = $link['last_update'] ? date('Y-m-d', $link['last_update']) : '';
            $aliveText = isset($link['alive']) ? ($link['alive'] ? '正常' : '异常') : '未知';
            $categoryName = '';
            if (!empty($link['category_id']) && isset($catNames[$link['category_id']])) {
                $categoryName = $catNames[$link['category_id']];
            }

            $card = str_replace(
                ['{url}', '{title}', '{description}', '{icon}', '{last_update}', '{alive}', '{category}'],
                [
                    htmlspecialchars($link['url']),
                    htmlspecialchars($link['title']),
                    htmlspecialchars($link['description'] ?? ''),
                    htmlspecialchars($icon),
                    htmlspecialchars($lastUpdate),
                    htmlspecialchars($aliveText),
                    htmlspecialchars($categoryName ?: '未分类')
                ],
                $template
            );

            if ($cardClass !== '') {
                $card = str_replace('friendlink-card', 'friendlink-card ' . htmlspecialchars($cardClass), $card);
            }
            $cardsHtml .= $card;
        }

        // 替换容器模板占位符
        $containerHtml = str_replace(
            ['{cards}', '{container_class}'],
            [$cardsHtml, htmlspecialchars($containerClass)],
            $containerTemplate
        );

        $output = '<style>' . $customCss . '</style>' . $sortSelectorHtml . $containerHtml;

        // 随机排序 JS（仅在渲染级缓存开启且排序为随机时附加）
        if ($useRenderCache && $currentSort === 'random') {
            $escapedClass = htmlspecialchars($containerClass);
            $output .= <<<HTML
<script>
(function() {
    var container = document.currentScript.previousElementSibling;
    if (container && container.classList.contains('{$escapedClass}')) {
        var cards = Array.from(container.children);
        for (var i = cards.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            container.appendChild(cards[j]);
        }
    }
})();
</script>
HTML;
        }

        // 写入整体渲染缓存
        if ($useRenderCache && isset($cacheFile)) {
            file_put_contents($cacheFile, $output, LOCK_EX);
        }

        return $output;
    }

    /**
     * 排序链接数组
     */
    private static function sortLinks(array &$links, string $order): void
    {
        switch ($order) {
            case 'created_desc':
                usort($links, function ($a, $b) { return $b['created'] <=> $a['created']; });
                break;
            case 'created_asc':
                usort($links, function ($a, $b) { return $a['created'] <=> $b['created']; });
                break;
            case 'title_asc':
                usort($links, function ($a, $b) { return strcasecmp($a['title'], $b['title']); });
                break;
            case 'title_desc':
                usort($links, function ($a, $b) { return strcasecmp($b['title'], $a['title']); });
                break;
            case 'random':
                shuffle($links);
                break;
            // manual: 保持原顺序（已按 sort, id 排序）
        }
    }

    /**
     * 构建访客排序下拉选择器
     */
    private static function buildSortSelector(string $currentSort, string $sortKey): string
    {
        $options = [
            'manual'       => '默认排序',
            'created_desc' => '最新添加',
            'created_asc'  => '最早添加',
            'title_asc'    => '标题 A-Z',
            'title_desc'   => '标题 Z-A',
            'random'       => '随机'
        ];

        $html = '<select class="friendlinks-sort-select" style="margin-bottom:10px;padding:5px 10px;border-radius:4px;border:1px solid #ccc;">';
        foreach ($options as $val => $label) {
            $selected = ($val === $currentSort) ? ' selected' : '';
            $html .= '<option value="' . $val . '"' . $selected . '>' . $label . '</option>';
        }
        $html .= '</select>';

        return <<<HTML
<div class="friendlinks-sort-toolbar">{$html}</div>
<script>
(function() {
    var toolbar = document.currentScript.previousElementSibling;
    if (toolbar && toolbar.classList.contains('friendlinks-sort-toolbar')) {
        var select = toolbar.querySelector('select');
        if (select) {
            select.addEventListener('change', function() {
                var d = new Date();
                d.setFullYear(d.getFullYear() + 1);
                document.cookie = '{$sortKey}=' + this.value + '; path=/; expires=' + d.toUTCString() + '; SameSite=Lax';
                window.location.reload();
            });
        }
    }
})();
</script>
HTML;
    }

    /**
     * 前台模板函数（兼容旧版调用）
     */
    public static function output(
        $containerClass = 'friendlinks-container',
        $cardClass = '',
        $categoryId = null,
        $uncategorizedMode = 1,
        $includeDeadMode = 0
    ) {
        echo self::renderLinks($containerClass, $cardClass, $categoryId, $uncategorizedMode, $includeDeadMode);
    }

    // ==================== 分类 CRUD ====================

    /**
     * 获取所有分类（按 sort 和 id 排序）
     */
    public static function getCategories()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        return $db->fetchAll(
            $db->select()->from($prefix . self::CATEGORY_TABLE)
               ->order('sort')->order('id')
        );
    }

    /**
     * 获取单个分类
     */
    public static function getCategory($id)
    {
        return Db::get()->fetchRow(
            Db::get()->select()->from(Db::get()->getPrefix() . self::CATEGORY_TABLE)
              ->where('id = ?', $id)
        );
    }

    /**
     * 添加分类
     */
    public static function addCategory($name, $sort = 0)
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::CATEGORY_TABLE;

        if ($sort <= 0) {
            $maxResult = $db->fetchRow($db->select('MAX(`sort`) as max_sort')->from($table));
            $sort = intval($maxResult['max_sort'] ?? 0) + 1;
        }

        return $db->query($db->insert($table)->rows([
            'name'    => $name,
            'sort'    => $sort,
            'created' => time()
        ]));
    }

    /**
     * 更新分类
     */
    public static function updateCategory($id, $name, $sort = null)
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::CATEGORY_TABLE;

        $rows = ['name' => $name];
        if ($sort !== null) {
            $rows['sort'] = intval($sort);
        }

        return $db->query($db->update($table)->rows($rows)->where('id = ?', $id));
    }

    /**
     * 删除分类（链接自动移至未分类）
     */
    public static function deleteCategory($id)
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        // 将该分类下的链接置为未分类
        $db->query($db->update($prefix . self::TABLE_NAME)
            ->rows(['category_id' => null])
            ->where('category_id = ?', $id));
        // 删除分类
        return $db->query($db->delete($prefix . self::CATEGORY_TABLE)
            ->where('id = ?', $id));
    }

    /**
     * 获取每个分类下的链接数量
     */
    public static function getCategoryLinkCounts()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        $counts = [];
        // 未分类
        $result = $db->fetchRow($db->select('COUNT(*) as cnt')
            ->from($prefix . self::TABLE_NAME)
            ->where('category_id IS NULL'));
        $counts['uncategorized'] = intval($result['cnt'] ?? 0);

        // 各分类
        $categories = self::getCategories();
        foreach ($categories as $cat) {
            $result = $db->fetchRow($db->select('COUNT(*) as cnt')
                ->from($prefix . self::TABLE_NAME)
                ->where('category_id = ?', $cat['id']));
            $counts[$cat['id']] = intval($result['cnt'] ?? 0);
        }

        return $counts;
    }

    // ==================== 缓存管理 ====================

    /**
     * 从缓存文件读取前台链接（status=1）
     */
    private static function getLinksFromCacheOnly()
    {
        if (self::$linksCache !== null) {
            return self::$linksCache;
        }

        $cacheFile = self::CACHE_DIR . 'friendlinks.cache.json';
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            self::$linksCache = is_array($data) ? $data : [];
        } else {
            self::$linksCache = [];
        }

        return self::$linksCache;
    }

    /**
     * 刷新数据缓存（重建 JSON 缓存，并清除所有渲染缓存）
     */
    public static function refreshCache()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        // 只缓存 status=1 的链接
        $links = $db->fetchAll(
            $db->select()->from($table)
               ->where('status = ?', 1)
               ->order('sort')->order('id')
        );

        $cacheFile = self::CACHE_DIR . 'friendlinks.cache.json';
        file_put_contents($cacheFile, json_encode($links, JSON_UNESCAPED_UNICODE), LOCK_EX);

        // 清空渲染缓存
        self::clearRenderedCache();

        // 更新内存缓存
        self::$linksCache = $links;

        return $links;
    }

    /**
     * 删除所有 HTML 渲染缓存文件
     */
    private static function clearRenderedCache()
    {
        foreach (glob(self::CACHE_DIR . 'friendlinks_rendered_*.html') as $file) {
            @unlink($file);
        }
    }

    // ==================== 网站信息抓取 ====================

    /**
     * 解析 HTML 提取标题、描述、图标
     */
    private static function parseSiteInfo($html, $finalUrl)
    {
        $info = ['title' => '', 'description' => '', 'icon' => ''];

        // 1. 标题
        $title = '';
        if (preg_match('/<meta[^>]+property=["\']og:title["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        if (empty($title) && preg_match('/<meta[^>]+name=["\']twitter:title["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        if (empty($title) && preg_match('/<title[^>]*>(.*?)<\/title>/i', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        $info['title'] = $title;

        // 2. 描述
        $desc = '';
        if (preg_match('/<meta[^>]+property=["\']og:description["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
            $desc = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        if (empty($desc) && preg_match('/<meta[^>]+name=["\']twitter:description["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
            $desc = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        if (empty($desc) && preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m)) {
            $desc = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        $info['description'] = $desc;

        // 3. 图标
        $iconFound = false;
        if (preg_match_all('/<link[^>]+>/i', $html, $linkTags)) {
            foreach ($linkTags[0] as $tag) {
                $rel = '';
                $href = '';
                if (preg_match('/rel=["\']([^"\']*)["\']/i', $tag, $relMatch)) {
                    $rel = strtolower($relMatch[1]);
                }
                if (preg_match('/href=["\']([^"\']*)["\']/i', $tag, $hrefMatch)) {
                    $href = $hrefMatch[1];
                }
                if (empty($href)) continue;

                $isIconRel = (strpos($rel, 'icon') !== false || strpos($rel, 'apple-touch-icon') !== false)
                             && strpos($rel, 'stylesheet') === false;

                if ($isIconRel) {
                    // 补全 URL
                    if (!preg_match('/^https?:\/\//i', $href)) {
                        $purl = parse_url($finalUrl);
                        $base = $purl['scheme'] . '://' . $purl['host'] . (isset($purl['port']) ? ':' . $purl['port'] : '');
                        if (strpos($href, '//') === 0) {
                            $href = $purl['scheme'] . ':' . $href;
                        } elseif ($href[0] === '/') {
                            $href = $base . $href;
                        } else {
                            $href = $base . '/' . $href;
                        }
                    }
                    $info['icon'] = $href;
                    $iconFound = true;
                    break;
                }
            }
        }

        return $info;
    }

    /**
     * 探测网站根目录常见图标
     */
    private static function probeIconFromRoot($finalUrl, $timeout = 10)
    {
        $purl = parse_url($finalUrl);
        $base = $purl['scheme'] . '://' . $purl['host'] . (isset($purl['port']) ? ':' . $purl['port'] : '');

        $paths = [
            '/favicon.ico', '/favicon.png', '/favicon.svg', '/favicon.jpg', '/favicon.jpeg',
            '/icon.png', '/icon.ico', '/apple-touch-icon.png', '/apple-touch-icon-precomposed.png',
            '/assets/favicon.ico', '/static/favicon.ico', '/images/favicon.ico'
        ];

        foreach ($paths as $path) {
            $url = $base . $path;
            // 先 HEAD
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
                CURLOPT_RETURNTRANSFER => true
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 400) {
                return $url;
            }
            // 再 GET 小范围
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
                CURLOPT_RANGE          => '0-8191',
                CURLOPT_BUFFERSIZE     => 8192
            ]);
            curl_exec($ch);
            $getCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($getCode >= 200 && $getCode < 400) {
                return $url;
            }
        }
        return '';
    }

    /**
     * 将 HTML 内容转换为 UTF-8
     */
    private static function convertToUtf8($content, $contentTypeHeader = '', $htmlBody = '')
    {
        $charset = '';

        // 从 Content-Type 头部提取
        if (preg_match('/charset\s*=\s*([^\s;]+)/i', $contentTypeHeader, $m)) {
            $charset = trim($m[1], '"\'');
        }

        // 从 HTML meta 中提取
        if (empty($charset) && !empty($htmlBody)) {
            if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([^"\'\s;>]+)/i', $htmlBody, $m)) {
                $charset = $m[1];
            }
        }

        // 兜底检测
        if (empty($charset)) {
            if (mb_check_encoding($content, 'UTF-8')) {
                return $content;
            }
            $charset = 'GBK';
        }

        if (strtoupper($charset) !== 'UTF-8') {
            $converted = @mb_convert_encoding($content, 'UTF-8', $charset);
            if ($converted !== false) {
                return $converted;
            }
            // 再试 GBK
            $converted = @mb_convert_encoding($content, 'UTF-8', 'GBK');
            if ($converted !== false) {
                return $converted;
            }
        }
        return $content;
    }

    /**
     * 抓取单个网站信息
     */
    public static function fetchSiteInfo($url)
    {
        $pluginOptions = self::getPluginOptions();
        $timeout = intval($pluginOptions->timeout ?? 10);
        $url = rtrim($url, '/');
        if (!preg_match('/^https?:\/\//', $url)) $url = 'https://' . $url;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_ENCODING       => 'gzip, deflate'
        ]);

        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $alive = ($code >= 200 && $code < 400);
        $info = ['title' => '', 'description' => '', 'icon' => '', 'alive' => $alive];

        if ($code === 200 && $html) {
            $html = self::convertToUtf8($html, $contentType, $html);
            $info = array_merge($info, self::parseSiteInfo($html, $finalUrl));
        }

        if (empty($info['icon'])) {
            $info['icon'] = self::probeIconFromRoot($finalUrl, $timeout);
        }

        return $info;
    }

    /**
     * 批量抓取网站信息（并发 + 图标探测）
     */
    public static function fetchMultiSiteInfo(array $urls)
    {
        $timeout = intval(self::getPluginOptions()->timeout ?? 10);
        $mh1 = curl_multi_init();
        $handles = [];

        foreach ($urls as $k => $url) {
            $url = rtrim($url, '/');
            if (!preg_match('/^https?:\/\//', $url)) $url = 'https://' . $url;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
                CURLOPT_ENCODING       => 'gzip, deflate'
            ]);
            curl_multi_add_handle($mh1, $ch);
            $handles[$k] = $ch;
        }

        do {
            curl_multi_exec($mh1, $active);
            if ($active) curl_multi_select($mh1);
        } while ($active);

        $results = [];
        $needProbe = [];

        foreach ($handles as $k => $ch) {
            $html        = curl_multi_getcontent($ch);
            $code        = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl    = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

            $alive = ($code >= 200 && $code < 400);
            $info  = ['title' => '', 'description' => '', 'icon' => '', 'alive' => $alive];

            if ($code === 200 && $html) {
                $html = self::convertToUtf8($html, $contentType, $html);
                $info = array_merge($info, self::parseSiteInfo($html, $finalUrl));
            }

            $results[$k] = $info;

            if (empty($info['icon']) && $finalUrl) {
                $needProbe[$k] = $finalUrl;
            }

            curl_multi_remove_handle($mh1, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh1);

        // 第二阶段：并发探测根目录图标
        if (!empty($needProbe)) {
            $mh2 = curl_multi_init();
            $probeHandles = [];
            $paths = [
                '/favicon.ico', '/favicon.png', '/favicon.svg',
                '/favicon.jpg', '/favicon.jpeg',
                '/icon.png', '/icon.ico',
                '/apple-touch-icon.png', '/apple-touch-icon-precomposed.png'
            ];

            foreach ($needProbe as $k => $finalUrl) {
                $purl = parse_url($finalUrl);
                $base = $purl['scheme'] . '://' . $purl['host'] . (isset($purl['port']) ? ':' . $purl['port'] : '');

                foreach ($paths as $path) {
                    $probeUrl = $base . $path;
                    $ch = curl_init($probeUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS      => 3,
                        CURLOPT_TIMEOUT        => $timeout,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_USERAGENT      => 'Mozilla/5.0',
                        CURLOPT_RANGE          => '0-8191',
                        CURLOPT_BUFFERSIZE     => 8192
                    ]);
                    curl_multi_add_handle($mh2, $ch);
                    $probeHandles[] = ['ch' => $ch, 'index' => $k, 'url' => $probeUrl];
                }
            }

            do {
                curl_multi_exec($mh2, $active);
                if ($active) curl_multi_select($mh2);
            } while ($active);

            $foundIcons = [];
            foreach ($probeHandles as $probeInfo) {
                $ch = $probeInfo['ch'];
                $k = $probeInfo['index'];

                if (!isset($foundIcons[$k])) {
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($httpCode >= 200 && $httpCode < 400) {
                        $foundIcons[$k] = $probeInfo['url'];
                    }
                }

                curl_multi_remove_handle($mh2, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh2);

            foreach ($foundIcons as $k => $iconUrl) {
                $results[$k]['icon'] = $iconUrl;
            }
        }

        return $results;
    }

    // ==================== 链接 CRUD ====================

    /**
     * 获取最大排序值
     */
    public static function getMaxSort()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $result = $db->fetchRow($db->select('MAX(`sort`) as max_sort')->from($prefix . self::TABLE_NAME));
        return intval($result['max_sort'] ?? 0);
    }

    /**
     * 获取链接总数（支持筛选）
     */
    public static function getLinksCount($includeHidden = true, $categoryFilter = 'all')
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $select = $db->select('COUNT(*) as cnt')->from($prefix . self::TABLE_NAME);

        if (!$includeHidden) {
            $select->where('status = ?', 1);
        }
        if ($categoryFilter === 'uncategorized') {
            $select->where('category_id IS NULL');
        } elseif ($categoryFilter === 'dead') {
            $select->where('alive = ?', 0);
        } elseif ($categoryFilter !== 'all') {
            $select->where('category_id = ?', intval($categoryFilter));
        }

        $result = $db->fetchRow($select);
        return intval($result['cnt'] ?? 0);
    }

    /**
     * 获取分页链接列表
     */
    public static function getLinksPaginated($includeHidden = true, $orderBy = 'sort', $categoryFilter = 'all', $limit = 10, $offset = 0)
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $select = $db->select()->from($prefix . self::TABLE_NAME);

        if (!$includeHidden) {
            $select->where('status = ?', 1);
        }
        if ($categoryFilter === 'uncategorized') {
            $select->where('category_id IS NULL');
        } elseif ($categoryFilter === 'dead') {
            $select->where('alive = ?', 0);
        } elseif ($categoryFilter !== 'all') {
            $select->where('category_id = ?', intval($categoryFilter));
        }

        switch ($orderBy) {
            case 'created_asc':  $select->order('created', Db::SORT_ASC); break;
            case 'created_desc': $select->order('created', Db::SORT_DESC); break;
            case 'title_asc':    $select->order('title', Db::SORT_ASC); break;
            case 'title_desc':   $select->order('title', Db::SORT_DESC); break;
            case 'random':       $select->order('RAND()'); break;
            default:             $select->order('sort')->order('id');
        }

        $select->limit($limit)->offset($offset);
        return $db->fetchAll($select);
    }

    /**
     * 获取所有链接（管理面板用）
     */
    public static function getAllLinks($includeHidden = true, $orderBy = 'sort', $categoryFilter = 'all')
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $select = $db->select()->from($prefix . self::TABLE_NAME);

        if (!$includeHidden) {
            $select->where('status = ?', 1);
        }
        if ($categoryFilter === 'uncategorized') {
            $select->where('category_id IS NULL');
        } elseif ($categoryFilter === 'dead') {
            $select->where('alive = ?', 0);
        } elseif ($categoryFilter !== 'all') {
            $select->where('category_id = ?', intval($categoryFilter));
        }

        switch ($orderBy) {
            case 'created_asc':  $select->order('created', Db::SORT_ASC); break;
            case 'created_desc': $select->order('created', Db::SORT_DESC); break;
            case 'title_asc':    $select->order('title', Db::SORT_ASC); break;
            case 'title_desc':   $select->order('title', Db::SORT_DESC); break;
            case 'random':       $select->order('RAND()'); break;
            default:             $select->order('sort')->order('id');
        }

        return $db->fetchAll($select);
    }

    /**
     * 获取单个链接
     */
    public static function getLink($id)
    {
        return Db::get()->fetchRow(
            Db::get()->select()->from(Db::get()->getPrefix() . self::TABLE_NAME)
              ->where('id = ?', $id)
        );
    }

    /**
     * 添加链接（自动抓取网站信息）
     */
    public static function addLink($data)
    {
        $db = Db::get();
        $table = $db->getPrefix() . self::TABLE_NAME;

        $info = self::fetchSiteInfo($data['url']);
        $title = $info['title'] ?: ($data['title'] ?: parse_url($data['url'], PHP_URL_HOST));
        $desc  = $info['description'] ?: ($data['description'] ?? '');
        $icon  = $info['icon'] ?: ($data['icon'] ?? '');
        $alive = $info['alive'] ? 1 : 0;

        $categoryId = isset($data['category_id']) && $data['category_id'] !== '' ? intval($data['category_id']) : null;

        return $db->query($db->insert($table)->rows([
            'url'           => $data['url'],
            'title'         => $title,
            'description'   => $desc,
            'icon'          => $icon,
            'status'        => $data['status'] ?? 1,
            'sort'          => $data['sort'] ?? 0,
            'category_id'   => $categoryId,
            'last_update'   => time(),
            'created'       => time(),
            'alive'         => $alive,
            'alive_checked' => time()
        ]));
    }

    /**
     * 更新链接（重新抓取）
     */
    public static function updateLink($id, $data)
    {
        $db = Db::get();
        $info = self::fetchSiteInfo($data['url']);
        $alive = $info['alive'] ? 1 : 0;

        $categoryId = isset($data['category_id']) && $data['category_id'] !== '' ? intval($data['category_id']) : null;

        return $db->query($db->update($db->getPrefix() . self::TABLE_NAME)->rows([
            'url'           => $data['url'],
            'title'         => $data['title'],
            'description'   => $data['description'] ?? '',
            'icon'          => $data['icon'] ?? '',
            'status'        => $data['status'],
            'sort'          => $data['sort'],
            'category_id'   => $categoryId,
            'alive'         => $alive,
            'alive_checked' => time()
        ])->where('id = ?', $id));
    }

    /**
     * 删除链接
     */
    public static function deleteLink($id)
    {
        return Db::get()->query(
            Db::get()->delete(Db::get()->getPrefix() . self::TABLE_NAME)
                ->where('id = ?', $id)
        );
    }

    /**
     * 更新单个链接的信息（仅抓取）
     */
    public static function updateLinkInfo($linkId)
    {
        set_time_limit(0);
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        $link = $db->fetchRow($db->select()->from($table)->where('id = ?', $linkId));
        if (!$link) return false;

        $info = self::fetchSiteInfo($link['url']);
        $updateData = [];
        if (!empty($info['title']))       $updateData['title']       = $info['title'];
        if (!empty($info['description'])) $updateData['description'] = $info['description'];
        if (!empty($info['icon']))        $updateData['icon']        = $info['icon'];
        $updateData['alive']         = $info['alive'] ? 1 : 0;
        $updateData['alive_checked'] = time();
        $updateData['last_update']   = time();

        $db->query($db->update($table)->rows($updateData)->where('id = ?', $linkId));
        self::refreshCache();
        return true;
    }

    /**
     * 更新所有可见链接信息（并发抓取）
     */
    public static function updateAllLinksInfo()
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        $links = $db->fetchAll($db->select()->from($table)->where('status = ?', 1));
        if (!$links) return 0;

        $updated = 0;
        $chunkSize = 10;
        $chunks = array_chunk($links, $chunkSize);

        foreach ($chunks as $chunk) {
            $urls = array_column($chunk, 'url');
            $infos = self::fetchMultiSiteInfo($urls);

            foreach ($chunk as $i => $link) {
                $info = $infos[$i];
                $updateData = [];
                if (!empty($info['title']))       $updateData['title']       = $info['title'];
                if (!empty($info['description'])) $updateData['description'] = $info['description'];
                if (!empty($info['icon']))        $updateData['icon']        = $info['icon'];
                $updateData['alive']         = $info['alive'] ? 1 : 0;
                $updateData['alive_checked'] = time();
                $updateData['last_update']   = time();

                $db->query($db->update($table)->rows($updateData)->where('id = ?', $link['id']));
                $updated++;
            }
        }

        self::refreshCache();
        return $updated;
    }

    /**
     * 重整排序值
     */
    public static function compactSorts()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        $links = $db->fetchAll($db->select()->from($table)->order('sort')->order('id'));
        $i = 1;
        foreach ($links as $link) {
            if ($link['sort'] != $i) {
                $db->query($db->update($table)->rows(['sort' => $i])->where('id = ?', $link['id']));
            }
            $i++;
        }
    }

    /**
     * 删除所有存活状态异常的链接
     */
    public static function deleteDeadLinks()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        $stmt = $db->query($db->delete($table)->where('alive = ?', 0));
        $deleted = is_object($stmt) ? $stmt->rowCount() : 0;
        self::refreshCache();
        return $deleted;
    }

    /**
     * 获取缓存状态信息（面板展示）
     */
    public static function getCacheInfo()
    {
        $cacheFile = self::CACHE_DIR . 'friendlinks.cache.json';
        $cacheTime = intval(self::getPluginOptions()->cacheTime ?? 604800);
        $info = [
            'exists'   => file_exists($cacheFile),
            'size'     => 0,
            'modified' => 0,
            'ttl'      => $cacheTime
        ];

        if ($info['exists']) {
            $info['size'] = filesize($cacheFile);
            $info['modified'] = filemtime($cacheFile);
            $info['remaining'] = max(0, $info['modified'] + $cacheTime - time());
            $info['expired'] = $info['remaining'] <= 0;
        }

        return $info;
    }
}