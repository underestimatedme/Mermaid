<?php
/**
 * Mermaid Plugin for Typecho
 * 
 * 专注于 Mermaid 图表渲染的 Typecho 插件
 * 提供完整、稳定、高性能的流程图、时序图、甘特图等图表渲染解决方案
 * 
 * @package Mermaid
 * @author Richard Yang
 * @version 1.3.4
 * @link https://your-domain.com/
 */

namespace TypechoPlugin\Mermaid;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Widget\Options;

class Plugin implements PluginInterface
{
    // 加载模式常量
    const LOAD_DISABLE = 0;
    const LOAD_SMART = 1;
    const LOAD_FORCE = 2;
    
    // CDN 源配置
    const CDN_SOURCES = [
        'jsdelivr' => [
            'name' => 'jsDelivr (推荐)',
            'mermaid' => 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js'
        ],
        'unpkg' => [
            'name' => 'UNPKG',
            'mermaid' => 'https://unpkg.com/mermaid@11/dist/mermaid.min.js'
        ],
        'china' => [
            'name' => '国内镜像 (BootCDN)',
            'mermaid' => 'https://cdn.bootcdn.net/ajax/libs/mermaid/11.10.1/mermaid.min.js'
        ]
    ];
    
    // Mermaid 主题配置
    const MERMAID_THEMES = [
        'default' => '默认 (default)',
        'dark' => '暗黑 (dark)',
        'forest' => '森林 (forest)',
        'neutral' => '中性 (neutral)'
    ];
    
    // 检测状态
    private static $needMermaid = false;
    private static $resourceIncluded = false;
    // 页面类型标记：是否在主页列表页
    private static $isIndexPage = false;

    /**
     * 激活插件
     */
    public static function activate()
    {
        \Typecho\Plugin::factory('Widget_Archive')->beforeRender = [__CLASS__, 'beforeRender'];
        \Typecho\Plugin::factory('Widget_Archive')->footer = [__CLASS__, 'footer'];
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->contentEx = [__CLASS__, 'parseContent'];
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->excerptEx = [__CLASS__, 'parseExcerpt'];
        // 关键：拦截 excerpt 过滤器，在摘要提取前移除 mermaid，避免从已转换的 content 中提取到 mermaid div
        // 使用完整的类名注册，确保过滤器能正确工作
        // 同时使用别名注册，确保兼容性
        \Typecho\Plugin::factory('\Widget\Base\Contents')->excerpt = [__CLASS__, 'filterExcerpt'];
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->excerpt = [__CLASS__, 'filterExcerpt'];
        \Typecho\Plugin::factory('Widget_Abstract_Comments')->contentEx = [__CLASS__, 'parseContent'];
        
        return _t('Mermaid 插件已激活，请配置相关设置。');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        return _t('Mermaid 插件已禁用。');
    }

    /**
     * 插件配置界面
     */
    public static function config(Form $form)
    {
        // ========== 核心设置部分 ==========
        echo '<h2>核心设置</h2>';
        
        // Mermaid 设置
        $mermaidMode = new Radio('mermaid_mode', [
            self::LOAD_DISABLE => _t('禁用'),
            self::LOAD_SMART => _t('智能按需加载 (推荐)'),
            self::LOAD_FORCE => _t('强制加载 (兼容性更好)')
        ], self::LOAD_SMART, _t('Mermaid 图表渲染'), _t('智能按需加载仅在检测到图表时加载资源，提升性能'));
        $form->addInput($mermaidMode);
        
        $mermaidTheme = new Select('mermaid_theme', self::MERMAID_THEMES, 'default', 
            _t('Mermaid 主题'), _t('选择图表的视觉主题'));
        $form->addInput($mermaidTheme);
        
        // ========== CDN 与性能设置 ==========
        echo '<h2>CDN 与性能</h2>';
        
        $cdnOptions = [];
        foreach (self::CDN_SOURCES as $key => $source) {
            $cdnOptions[$key] = _t($source['name']);
        }
        
        $cdnSource = new Radio('cdn_source', $cdnOptions, 'jsdelivr', 
            _t('CDN 源选择'), _t('选择资源加载的 CDN 服务商'));
        $form->addInput($cdnSource);
        
        $lazyLoad = new Radio('lazy_load', [
            '0' => _t('禁用'),
            '1' => _t('启用 (推荐)')
        ], '1', _t('懒加载'), _t('延迟渲染图表直到进入视口'));
        $form->addInput($lazyLoad);
        
        // ========== 高级功能设置 ==========
        echo '<h2>高级功能</h2>';
        
        $pjaxSupport = new Radio('pjax_support', [
            '0' => _t('禁用'),
            '1' => _t('启用')
        ], '1', _t('Pjax 兼容'), _t('支持单页应用动态重新渲染'));
        $form->addInput($pjaxSupport);
        
        $debugMode = new Radio('debug_mode', [
            '0' => _t('禁用'),
            '1' => _t('启用')
        ], '0', _t('调试模式'), _t('在控制台输出调试信息'));
        $form->addInput($debugMode);
    }

    /**
     * 个人配置界面
     */
    public static function personalConfig(Form $form)
    {
        // 暂无个人配置需求
    }

    /**
     * 页面渲染前处理
     */
    public static function beforeRender($archive)
    {
        // 重置检测状态
        self::$needMermaid = false;
        self::$resourceIncluded = false;
        
        // 关键修复：如果是主页列表页（index），强制禁用 mermaid 检测
        // 这样可以确保主页不会加载 mermaid 脚本，即使有文章包含 mermaid
        self::$isIndexPage = $archive->is('index');
        if (self::$isIndexPage) {
            // 主页列表页完全禁用 mermaid，避免加载脚本和影响布局
            self::$needMermaid = false;
        }
    }

    /**
     * 内容解析 - 回退到有效的转换逻辑
     */
    public static function parseContent($content, $widget = null)
    {
        if (empty($content)) {
            return $content;
        }
        
        $config = Options::alloc()->plugin('Mermaid');
        
        // 关键修复：如果是主页列表页，完全跳过 mermaid 处理
        // 这是最可靠的判断方式，因为 beforeRender 已经设置了标记
        if (self::$isIndexPage) {
            // 在主页列表页，需要检查是否在摘要处理流程中
            // 因为 excerpt 流程会先调用 contentEx，此时不应转换和检测 mermaid
            $isInExcerptFlow = false;
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
            foreach ($backtrace as $frame) {
                // 检查是否在 excerpt 相关的调用中
                if (isset($frame['function']) && 
                    (strpos($frame['function'], 'excerpt') !== false || 
                     strpos($frame['function'], 'Excerpt') !== false ||
                     strpos($frame['function'], '___excerpt') !== false)) {
                    $isInExcerptFlow = true;
                    break;
                }
                // 检查是否在摘要输出方法中（excerpt 方法会调用 $this->excerpt 属性）
                if (isset($frame['function']) && 
                    strpos($frame['function'], 'excerpt') !== false &&
                    isset($frame['class']) &&
                    (strpos($frame['class'], 'Contents') !== false || 
                     strpos($frame['class'], 'Archive') !== false)) {
                    $isInExcerptFlow = true;
                    break;
                }
            }
            
            // 主页列表页：如果在摘要流程中，跳过处理；否则也跳过（因为主页不应该有 mermaid）
            return $content; // 主页列表页不转换、不检测
        }
        
        // 文章页面（single）：正常处理，不检查调用栈
        // 因为文章页面的 contentEx 调用是正常的，不应该被跳过
        
        // 转换 Markdown 代码块为 mermaid div（仅在完整内容页面）
        $content = self::convertMermaidCodeBlocks($content);
        
        // 检测内容类型（仅在完整内容中检测，摘要中不检测）
        if ($config->mermaid_mode != self::LOAD_DISABLE) {
            self::$needMermaid = self::$needMermaid || self::detectMermaid($content);
        }
        
        return $content;
    }
    
    /**
     * 拦截 excerpt 过滤器
     * 在摘要提取前，从 content 中移除所有 mermaid，确保摘要不包含 mermaid
     * 
     * 这是关键：Typecho 的 excerpt 流程是：
     * 1. $this->content (已转换 mermaid，包含 <div class="mermaid">...)
     * 2. filter('excerpt', $this->content, $this) <- 这里拦截，必须在摘要提取前移除 mermaid
     * 3. explode('<!--more-->', $content) 提取摘要
     * 4. filter('excerptEx', $excerpt, $this) <- parseExcerpt 作为第二层防护
     * 
     * 注意：Typecho 的 filter 方法参数顺序是：[当前结果, ...args, 原始值]
     * 所以当调用 filter('excerpt', $this->content, $this) 时，
     * 参数为：[当前content, $this, 原始content]
     */
    public static function filterExcerpt($content, $widget = null, $originalContent = null)
    {
        if (empty($content)) {
            return $content;
        }
        
        // 在摘要提取前，彻底移除所有 mermaid 代码
        // 这是第一层防护，确保从 content 中提取摘要时不包含 mermaid
        
        // 先检查是否真的包含 mermaid，避免不必要的处理
        $hasMermaid = false;
        if (strpos($content, 'mermaid') !== false || 
            strpos($content, 'lang-mermaid') !== false || 
            strpos($content, 'language-mermaid') !== false ||
            preg_match('/<div[^>]*class\s*=\s*["\'][^"\']*mermaid/i', $content)) {
            $hasMermaid = true;
        }
        
        if (!$hasMermaid) {
            return $content;
        }
        
        // 使用循环处理，确保彻底清除所有可能的变体
        $maxIterations = 10; // 增加迭代次数确保彻底清除
        $iteration = 0;
        $previousContent = '';
        
        while ($content !== $previousContent && $iteration < $maxIterations) {
            $previousContent = $content;
            
            // 移除完整的 mermaid div（包括所有属性变体）
            // 使用非贪婪匹配，但确保匹配完整的标签
            $content = preg_replace('/<div[^>]*mermaid[^>]*>.*?<\/div>/is', '', $content);
            $content = preg_replace('/<div[^>]*class\s*=\s*["\']?[^"\']*mermaid[^"\']*["\']?[^>]*>.*?<\/div>/is', '', $content);
            
            // 移除不完整的 mermaid div 标签（可能被截断或未闭合）
            // 匹配到内容结尾或下一个标签开始
            $content = preg_replace('/<div[^>]*mermaid[^>]*>.*/is', '', $content);
            $content = preg_replace('/<div[^>]*class\s*=\s*["\']?[^"\']*mermaid[^"\']*["\']?[^>]*>.*/is', '', $content);
            
            // 移除未转换的代码块（所有可能的格式）
            $content = preg_replace('/<pre\s*[^>]*>.*?<code[^>]*class\s*=\s*["\']?[^"\']*lang-mermaid[^"\']*["\']?[^>]*>.*?<\/code>.*?<\/pre>/is', '', $content);
            $content = preg_replace('/<pre\s*[^>]*>.*?<code[^>]*class\s*=\s*["\']?[^"\']*language-mermaid[^"\']*["\']?[^>]*>.*?<\/code>.*?<\/pre>/is', '', $content);
            $content = preg_replace('/<pre[^>]*>.*?<code[^>]*mermaid[^>]*>.*?<\/code>.*?<\/pre>/is', '', $content);
            
            // 移除可能被截断的不完整代码块
            $content = preg_replace('/<pre[^>]*>.*?<code[^>]*lang-mermaid[^>]*>.*/is', '', $content);
            $content = preg_replace('/<pre[^>]*>.*?<code[^>]*language-mermaid[^>]*>.*/is', '', $content);
            $content = preg_replace('/<pre[^>]*>.*?<code[^>]*mermaid[^>]*>.*/is', '', $content);
            
            $iteration++;
        }
        
        // 清理残留的属性（从其他标签上）
        $content = preg_replace('/\s+class\s*=\s*["\'][^"\']*mermaid[^"\']*["\']/i', '', $content);
        $content = preg_replace('/\s+data-mermaid[^=]*=["\'][^"\']*["\']/i', '', $content);
        
        // 移除残留的空标签
        $content = preg_replace('/<div[^>]*>\s*<\/div>/i', '', $content);
        $content = preg_replace('/<pre[^>]*>\s*<\/pre>/i', '', $content);
        $content = preg_replace('/<code[^>]*>\s*<\/code>/i', '', $content);
        
        // 清理多余的空白
        $content = preg_replace('/\n\s*\n\s*\n/', "\n\n", $content);
        $content = preg_replace('/[ \t]+/', ' ', $content); // 压缩多个空格
        $content = trim($content);
        
        return $content;
    }

    /**
     * 转换 Markdown 代码块为 mermaid div - 有效的转换逻辑
     */
    private static function convertMermaidCodeBlocks($content)
    {
        // 匹配 Typecho 解析后的 Markdown 代码块结构
        // Typecho 的 Markdown 解析器会将 class="mermaid" 转换为 class="lang-mermaid"
        // 支持多种格式：双引号、单引号、无引号等
        
        // 1. 匹配标准的 lang-mermaid 格式（最常见）
        $pattern = '/<pre><code\s+class\s*=\s*["\']?lang-mermaid["\']?[^>]*>(.*?)<\/code><\/pre>/is';
        $content = preg_replace_callback($pattern, function($matches) {
            if (empty($matches[1])) {
                return $matches[0];
            }
            $mermaidCode = trim($matches[1]);
            $mermaidCode = self::cleanMermaidCode($mermaidCode);
            return '<div class="mermaid">' . $mermaidCode . '</div>';
        }, $content);
        
        // 2. 匹配可能的 language-mermaid 格式
        $pattern = '/<pre><code\s+class\s*=\s*["\']?language-mermaid["\']?[^>]*>(.*?)<\/code><\/pre>/is';
        $content = preg_replace_callback($pattern, function($matches) {
            if (empty($matches[1])) {
                return $matches[0];
            }
            $mermaidCode = trim($matches[1]);
            $mermaidCode = self::cleanMermaidCode($mermaidCode);
            return '<div class="mermaid">' . $mermaidCode . '</div>';
        }, $content);
        
        // 3. 匹配可能包含 mermaid 的其他 class 格式
        $pattern = '/<pre><code[^>]*class\s*=\s*["\'][^"\']*mermaid[^"\']*["\'][^>]*>(.*?)<\/code><\/pre>/is';
        $content = preg_replace_callback($pattern, function($matches) {
            if (empty($matches[1])) {
                return $matches[0];
            }
            $mermaidCode = trim($matches[1]);
            $mermaidCode = self::cleanMermaidCode($mermaidCode);
            return '<div class="mermaid">' . $mermaidCode . '</div>';
        }, $content);
        
        return $content;
    }

    /**
     * 清理 Mermaid 代码
     */
    private static function cleanMermaidCode($code)
    {
        // 移除开头和结尾的空白字符
        $code = trim($code);
        
        // 处理 HTML 实体编码
        $code = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 移除可能的多余转义字符
        $code = str_replace(['\\n', '\\t', '\\r'], ["\n", "\t", "\r"], $code);
        
        // 统一换行符
        $code = preg_replace('/\r\n?/', "\n", $code);
        
        // 移除多余的空行
        $code = preg_replace('/\n\s*\n/', "\n", $code);
        
        return $code;
    }

    /**
     * 摘要解析 - 主页列表专用：完全禁用 mermaid，彻底移除所有相关代码
     * 
     * 这是第二层防护：即使 filterExcerpt 有遗漏，这里也会彻底清理
     */
    public static function parseExcerpt($content, $widget = null)
    {
        if (empty($content)) {
            return $content;
        }
        
        // 在摘要中完全移除 mermaid 代码，避免影响布局
        // 主页列表的摘要不需要渲染图表，只显示文本摘要
        // 彻底移除所有可能的 mermaid 代码，包括不完整的标签
        
        // 使用循环处理，确保彻底清除
        $maxIterations = 5;
        $iteration = 0;
        $previousContent = '';
        while ($content !== $previousContent && $iteration < $maxIterations) {
            $previousContent = $content;
            
            // 第一步：移除完整的 mermaid div（包括所有属性变体）
            $content = preg_replace('/<div[^>]*mermaid[^>]*>.*?<\/div>/is', '', $content);
            $content = preg_replace('/<div[^>]*class\s*=\s*["\']?[^"\']*mermaid[^"\']*["\']?[^>]*>.*?<\/div>/is', '', $content);
            
            // 第二步：移除不完整的 mermaid div 标签（可能被截断）
            $content = preg_replace('/<div[^>]*mermaid[^>]*>.*/is', '', $content);
            $content = preg_replace('/<div[^>]*class\s*=\s*["\']?[^"\']*mermaid[^"\']*["\']?[^>]*>.*/is', '', $content);
            
            // 第三步：移除未转换的代码块（所有可能的格式）
            $content = preg_replace('/<pre\s*[^>]*>.*?<code[^>]*class\s*=\s*["\']?[^"\']*lang-mermaid[^"\']*["\']?[^>]*>.*?<\/code>.*?<\/pre>/is', '', $content);
            $content = preg_replace('/<pre\s*[^>]*>.*?<code[^>]*class\s*=\s*["\']?[^"\']*language-mermaid[^"\']*["\']?[^>]*>.*?<\/code>.*?<\/pre>/is', '', $content);
            $content = preg_replace('/<pre[^>]*>.*?<code[^>]*mermaid[^>]*>.*?<\/code>.*?<\/pre>/is', '', $content);
            
            // 第四步：移除可能被截断的不完整代码块
            $content = preg_replace('/<pre[^>]*>.*?<code[^>]*lang-mermaid[^>]*>.*/is', '', $content);
            $content = preg_replace('/<pre[^>]*>.*?<code[^>]*language-mermaid[^>]*>.*/is', '', $content);
            $content = preg_replace('/<pre[^>]*>.*?<code[^>]*mermaid[^>]*>.*/is', '', $content);
            
            $iteration++;
        }
        
        // 第五步：移除所有包含 mermaid 的相关属性
        $content = preg_replace('/\s*class\s*=\s*["\'][^"\']*mermaid[^"\']*["\']/i', '', $content);
        $content = preg_replace('/\s*data-mermaid[^=]*=["\'][^"\']*["\']/i', '', $content);
        
        // 第六步：移除可能残留的空标签和多余空白
        $content = preg_replace('/<div[^>]*><\/div>/i', '', $content);
        $content = preg_replace('/<pre[^>]*><\/pre>/i', '', $content);
        $content = preg_replace('/<code[^>]*><\/code>/i', '', $content);
        
        // 第七步：清理多余的空白行和空格
        $content = preg_replace('/\n\s*\n\s*\n/', "\n\n", $content);
        $content = trim($content);
        
        // 确保返回的内容不包含任何 mermaid 相关的 HTML
        // 摘要中完全不检测 mermaid，不调用 parseContent，不进行任何转换
        return $content;
    }

    /**
     * 页脚资源输出
     */
    public static function footer()
    {
        // 关键修复：如果是主页列表页，完全跳过脚本输出
        // 这样可以确保主页不会加载 mermaid 脚本，解决布局问题和加载问题
        if (self::$isIndexPage) {
            return;
        }
        
        // 防止重复输出
        if (self::$resourceIncluded) {
            return;
        }
        
        $config = Options::alloc()->plugin('Mermaid');
        $cdnSource = $config->cdn_source ?: 'jsdelivr';
        $cdnConfig = self::CDN_SOURCES[$cdnSource] ?? self::CDN_SOURCES['jsdelivr'];
        
        $resourceContent = '';
        
        // Mermaid 资源加载决策逻辑
        // 对于按需加载模式，如果服务端检测失败，在前端也会检查DOM中是否有.mermaid元素
        $isAvailableMermaid = $config->mermaid_mode == self::LOAD_FORCE || 
                             (self::$needMermaid && $config->mermaid_mode == self::LOAD_SMART);
        
        // 如果是按需加载且服务端未检测到，但在前端检测到，也加载脚本
        $enableClientDetection = ($config->mermaid_mode == self::LOAD_SMART && !self::$needMermaid);
        
        if ($config->mermaid_mode != self::LOAD_DISABLE) {
            // 统一的前端检测与加载逻辑：仅在需要时加载 mermaid 脚本
            // 关键：主页列表页不应该有 mermaid 元素（已在 filterExcerpt 中移除），所以不会加载脚本
            $resourceContent .= '<script>';
            $resourceContent .= '(function() {';
            $resourceContent .= '  var CDN = ' . json_encode($cdnConfig['mermaid']) . ';';
            $resourceContent .= '  var SMART_MODE = ' . json_encode($config->mermaid_mode == self::LOAD_SMART) . ';';
            $resourceContent .= '  var FORCE_MODE = ' . json_encode($config->mermaid_mode == self::LOAD_FORCE) . ';';
            $resourceContent .= '  var SERVER_NEED = ' . json_encode(self::$needMermaid) . ';';
            // 检测 DOM 中的 mermaid 元素
            // 注意：如果 filterExcerpt 和 parseExcerpt 正确工作，主页列表页不应该有 mermaid 元素
            $resourceContent .= '  function hasMermaidInDOM(){ return !!document.querySelector(".mermaid, pre code.lang-mermaid, pre code.language-mermaid"); }';
            $resourceContent .= '  function loadScriptOnce(){';
            $resourceContent .= '    if (window.__mermaidLoading || window.__mermaidLoaded) return;';
            $resourceContent .= '    window.__mermaidLoading = true;';
            $resourceContent .= '    var s = document.createElement("script"); s.src = CDN; s.async = true;';
            $resourceContent .= '    s.onload = function(){ window.__mermaidLoaded = true; window.__mermaidLoading = false; };';
            $resourceContent .= '    document.head.appendChild(s);';
            $resourceContent .= '  }';
            $resourceContent .= '  function shouldLoad(){ if (FORCE_MODE) return true; if (SERVER_NEED) return true; if (SMART_MODE) return hasMermaidInDOM(); return false; }';
            $resourceContent .= '  function boot(){ if (shouldLoad()) loadScriptOnce(); }';
            $resourceContent .= '  if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", boot); } else { boot(); }';
            $resourceContent .= '})();';
            $resourceContent .= '</script>';

            $resourceContent .= '<script>';
            // 等待脚本加载完成并初始化
            $resourceContent .= '(function() {';
            $resourceContent .= '  var maxRetries = 50; var retryCount = 0;';
            $resourceContent .= '  function initMermaid() {';
            $resourceContent .= '    retryCount++; if (retryCount > maxRetries) { console.error("Mermaid failed to load after retries"); return; }';
            $resourceContent .= '    if (typeof mermaid === "undefined") { setTimeout(initMermaid, 50); return; }';
            
            // 初始化配置
            $resourceContent .= '    mermaid.initialize({';
            $resourceContent .= '      startOnLoad: ' . ((string)$config->lazy_load === '1' ? 'false' : 'true') . ',';
            $resourceContent .= '      theme: "' . ($config->mermaid_theme ?: 'default') . '",';
            $resourceContent .= '      securityLevel: "loose",';
            $resourceContent .= '      flowchart: { htmlLabels: true, curve: "basis" }';
            $resourceContent .= '    });';

            // 渲染函数（兼容 Mermaid 10.x API）
            $resourceContent .= '    function runMermaidFor(nodes) {';
            $resourceContent .= '      try {';
            $resourceContent .= '        if (nodes && nodes.length) {';
            $resourceContent .= '          // Mermaid 10.x 支持传入节点数组';
            $resourceContent .= '          if (typeof mermaid.run === "function") {';
            $resourceContent .= '            mermaid.run({ nodes: nodes });';
            $resourceContent .= '            return;';
            $resourceContent .= '          }';
            $resourceContent .= '          // 兼容旧版 API';
            $resourceContent .= '          if (typeof mermaid.init === "function") {';
            $resourceContent .= '            mermaid.init(undefined, nodes);';
            $resourceContent .= '            return;';
            $resourceContent .= '          }';
            $resourceContent .= '        }';
            $resourceContent .= '        // 渲染所有未处理的图表';
            $resourceContent .= '        var allNodes = document.querySelectorAll(".mermaid:not([data-processed])");';
            $resourceContent .= '        if (allNodes.length > 0) {';
            $resourceContent .= '          if (typeof mermaid.run === "function") {';
            $resourceContent .= '            mermaid.run();';
            $resourceContent .= '            return;';
            $resourceContent .= '          }';
            $resourceContent .= '          if (typeof mermaid.init === "function") {';
            $resourceContent .= '            mermaid.init(undefined, allNodes);';
            $resourceContent .= '            return;';
            $resourceContent .= '          }';
            $resourceContent .= '        }';
            $resourceContent .= '      } catch (e) {';
            $resourceContent .= '        console.error("Mermaid render error", e);';
            $resourceContent .= '      }';
            $resourceContent .= '    }';

            // 懒加载：IntersectionObserver
            $resourceContent .= '    if (' . ((string)$config->lazy_load === '1' ? 'true' : 'false') . ') {';
            $resourceContent .= '      var observe = function() {';
            $resourceContent .= '        var mermaidNodes = document.querySelectorAll(".mermaid");';
            $resourceContent .= '        if (mermaidNodes.length === 0) return;';
            
            $resourceContent .= '        if (!("IntersectionObserver" in window)) {';
            $resourceContent .= '          // 不支持 IntersectionObserver，直接渲染';
            $resourceContent .= '          runMermaidFor();';
            $resourceContent .= '          return;';
            $resourceContent .= '        }';
            
            // 检查初始可见的元素并立即渲染
            $resourceContent .= '        var visibleNodes = [];';
            $resourceContent .= '        var rect = null;';
            $resourceContent .= '        for (var i = 0; i < mermaidNodes.length; i++) {';
            $resourceContent .= '          var node = mermaidNodes[i];';
            $resourceContent .= '          if (node.hasAttribute("data-processed")) continue;';
            $resourceContent .= '          rect = node.getBoundingClientRect();';
            $resourceContent .= '          // 检查元素是否在视口内（考虑 margin）';
            $resourceContent .= '          if (rect.top < window.innerHeight + 50 && rect.bottom > -50 && rect.left < window.innerWidth + 50 && rect.right > -50) {';
            $resourceContent .= '            visibleNodes.push(node);';
            $resourceContent .= '          }';
            $resourceContent .= '        }';
            $resourceContent .= '        // 立即渲染初始可见的元素';
            $resourceContent .= '        if (visibleNodes.length > 0) {';
            $resourceContent .= '          visibleNodes.forEach(function(node) {';
            $resourceContent .= '            node.setAttribute("data-processed", "true");';
            $resourceContent .= '          });';
            $resourceContent .= '          runMermaidFor(visibleNodes);';
            $resourceContent .= '        }';
            
            $resourceContent .= '        var io = new IntersectionObserver(function(entries) {';
            $resourceContent .= '          entries.forEach(function(entry) {';
            $resourceContent .= '            if (entry.isIntersecting) {';
            $resourceContent .= '              var target = entry.target;';
            $resourceContent .= '              if (!target.hasAttribute("data-processed")) {';
            $resourceContent .= '                target.setAttribute("data-processed", "true");';
            $resourceContent .= '                runMermaidFor([target]);';
            $resourceContent .= '              }';
            $resourceContent .= '              io.unobserve(target);';
            $resourceContent .= '            }';
            $resourceContent .= '          });';
            $resourceContent .= '        }, { rootMargin: "50px" });';
            
            $resourceContent .= '        // 观察所有未处理的元素';
            $resourceContent .= '        mermaidNodes.forEach(function(el) {';
            $resourceContent .= '          if (!el.hasAttribute("data-processed")) {';
            $resourceContent .= '            io.observe(el);';
            $resourceContent .= '          }';
            $resourceContent .= '        });';
            $resourceContent .= '      };';
            
            $resourceContent .= '      // DOMContentLoaded 后开始观察';
            $resourceContent .= '      if (document.readyState === "loading") {';
            $resourceContent .= '        document.addEventListener("DOMContentLoaded", observe);';
            $resourceContent .= '      } else {';
            $resourceContent .= '        observe();';
            $resourceContent .= '      }';
            
            // PJAX 支持
            $resourceContent .= '      if (' . ((string)$config->pjax_support === '1' ? 'true' : 'false') . ') {';
            $resourceContent .= '        document.addEventListener("pjax:complete", function() {';
            $resourceContent .= '          setTimeout(observe, 100);';
            $resourceContent .= '        });';
            $resourceContent .= '      }';
            
            $resourceContent .= '    } else {';
            // 非懒加载：直接渲染
            $resourceContent .= '      function renderAll() {';
            $resourceContent .= '        runMermaidFor();';
            $resourceContent .= '      }';
            $resourceContent .= '      if (document.readyState === "loading") {';
            $resourceContent .= '        document.addEventListener("DOMContentLoaded", renderAll);';
            $resourceContent .= '      } else {';
            $resourceContent .= '        renderAll();';
            $resourceContent .= '      }';
            
            // PJAX 支持
            $resourceContent .= '      if (' . ((string)$config->pjax_support === '1' ? 'true' : 'false') . ') {';
            $resourceContent .= '        document.addEventListener("pjax:complete", function() {';
            $resourceContent .= '          setTimeout(runMermaidFor, 100);';
            $resourceContent .= '        });';
            $resourceContent .= '      }';
            $resourceContent .= '    }';
            
            $resourceContent .= '  }';
            
            // 开始初始化
            $resourceContent .= '  function startInit() {';
            $resourceContent .= '    // 如果脚本已加载完成，立即初始化';
            $resourceContent .= '    if (window.__mermaidLoaded && typeof mermaid !== "undefined") {';
            $resourceContent .= '      initMermaid();';
            $resourceContent .= '      return;';
            $resourceContent .= '    }';
            $resourceContent .= '    // 监听脚本加载完成事件';
            $resourceContent .= '    var checkInterval = setInterval(function() {';
            $resourceContent .= '      if (window.__mermaidLoaded || typeof mermaid !== "undefined") {';
            $resourceContent .= '        clearInterval(checkInterval);';
            $resourceContent .= '        if (document.readyState === "loading") {';
            $resourceContent .= '          document.addEventListener("DOMContentLoaded", initMermaid);';
            $resourceContent .= '        } else {';
            $resourceContent .= '          initMermaid();';
            $resourceContent .= '        }';
            $resourceContent .= '      }';
            $resourceContent .= '    }, 50);';
            $resourceContent .= '    // 超时保护';
            $resourceContent .= '    setTimeout(function() { clearInterval(checkInterval); }, 5000);';
            $resourceContent .= '  }';
            $resourceContent .= '  startInit();';
            $resourceContent .= '})();';
            $resourceContent .= '</script>';
            
            // Mermaid CSS 样式（最小侵入，避免影响主题布局）
            // 限制样式只应用到文章内容区域，避免影响列表页布局
            $resourceContent .= '<style>';
            $resourceContent .= '.post-content .mermaid, article .mermaid, [itemprop="articleBody"] .mermaid { margin: 1rem 0; overflow: hidden; }';
            $resourceContent .= '.mermaid svg { max-width: 100%; height: auto; display: block; }';
            $resourceContent .= '.mermaid .label { font-family: inherit; }';
            $resourceContent .= '@media (max-width: 768px) { .post-content .mermaid, article .mermaid, [itemprop="articleBody"] .mermaid { margin: 0.5rem 0; } }';
            $resourceContent .= '@media print { .mermaid { break-inside: avoid; } }';
            $resourceContent .= '</style>';
        }
        
        // 输出调试信息
        if ($config->debug_mode) {
            $debugInfo = [
                'mermaid' => [
                    'need' => self::$needMermaid, 
                    'available' => $isAvailableMermaid
                ],
                'cdn_source' => $cdnSource
            ];
            $resourceContent .= '<!-- Mermaid Debug: ' . json_encode($debugInfo) . ' -->';
        }
        
        if (!empty($resourceContent)) {
            echo $resourceContent;
            self::$resourceIncluded = true;
        }
    }

    /**
     * 检测 Mermaid 图表 - 与 convertMermaidCodeBlocks 保持一致
     */
    private static function detectMermaid($content)
    {
        // 1. 检测已转换的 mermaid div（多种格式）
        if (preg_match('/<div[^>]*class\s*=\s*["\']?[^"\']*mermaid[^"\']*["\']?[^>]*>.*?<\/div>/is', $content)) {
            return true;
        }
        
        // 2. 检测 Typecho 解析后的 Markdown 代码块（lang-mermaid）
        if (preg_match('/<pre><code\s+class\s*=\s*["\']?lang-mermaid["\']?[^>]*>.*?<\/code><\/pre>/is', $content)) {
            return true;
        }
        
        // 3. 检测可能的 language-mermaid 格式
        if (preg_match('/<pre><code\s+class\s*=\s*["\']?language-mermaid["\']?[^>]*>.*?<\/code><\/pre>/is', $content)) {
            return true;
        }
        
        // 4. 检测包含 mermaid 的其他 class 格式
        if (preg_match('/<pre><code[^>]*class\s*=\s*["\'][^"\']*mermaid[^"\']*["\'][^>]*>.*?<\/code><\/pre>/is', $content)) {
            return true;
        }
        
        return false;
    }

}
