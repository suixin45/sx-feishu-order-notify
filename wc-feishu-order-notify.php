<?php
/**
 * Plugin Name: WC - Feishu Order Notify
 * Plugin URI: https://github.com/suixin45/wc-feishu-order-notify
 * Description: 将 WooCommerce 订单状态通过 Feishu Webhook 发送到指定群聊...
 * Version: 1.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: suixin45
 * Author URI: https://github.com/suixin45
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: feishu-order-notify
 */

if (!defined('ABSPATH')) exit;

define('FEISHU_NOTIFY_OPTION_GROUP', 'feishu_order_notify_options');
define('FEISHU_NOTIFY_OPTION_NAME', 'feishu_order_notify_settings');
define('FEISHU_NOTIFY_PREFIX', 'https://open.feishu.cn/open-apis/bot/v2/hook/');
define('FEISHU_NOTIFY_LOG_TRANSIENT', 'feishu_order_notify_logs');
define('FEISHU_NOTIFY_LOG_LIMIT', 15);

// 检查依赖
add_action('plugins_loaded', 'feishu_order_notify_check_dependencies');
function feishu_order_notify_check_dependencies() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>Feishu Order Notify 需要 WooCommerce 插件！</p></div>';
        });
    }
}

register_uninstall_hook(__FILE__, 'feishu_order_notify_cleanup');
function feishu_order_notify_cleanup() {
    delete_option(FEISHU_NOTIFY_OPTION_NAME);
    delete_transient(FEISHU_NOTIFY_LOG_TRANSIENT);
}

add_action('admin_menu', 'feishu_order_notify_add_admin_menu');
add_action('admin_init', 'feishu_order_notify_settings_init');

function feishu_order_notify_add_admin_menu() {
    add_options_page(
        '飞书订单通知',
        'Feishu 订单通知',
        'manage_options',
        'feishu_order_notify',
        'feishu_order_notify_options_page'
    );
}

function feishu_order_notify_settings_init() {
    register_setting(
        FEISHU_NOTIFY_OPTION_GROUP, 
        FEISHU_NOTIFY_OPTION_NAME,
        'feishu_order_notify_sanitize_options'
    );
    add_settings_section('feishu_order_notify_section', '', null, 'feishu_order_notify');
    add_settings_field('webhook_key', 'Webhook 地址后缀', 'feishu_order_notify_webhook_render', 'feishu_order_notify', 'feishu_order_notify_section');
    add_settings_field('statuses', '监听订单状态', 'feishu_order_notify_statuses_render', 'feishu_order_notify', 'feishu_order_notify_section');
}

function feishu_order_notify_sanitize_options($input) {
    $clean = [];
    
    if (isset($input['webhook_key'])) {
        $clean['webhook_key'] = sanitize_text_field($input['webhook_key']);
    }
    
    if (isset($input['statuses'])) {
        $clean['statuses'] = array_map('sanitize_text_field', (array)$input['statuses']);
    }
    
    return $clean;
}

function feishu_order_notify_webhook_render() {
    $options = get_option(FEISHU_NOTIFY_OPTION_NAME);
    $value = isset($options['webhook_key']) ? esc_attr($options['webhook_key']) : '';
    echo '<input type="text" name="'.esc_attr(FEISHU_NOTIFY_OPTION_NAME).'[webhook_key]" value="'.esc_attr($value).'" style="width:60%;" placeholder="例如：ca95b85c-2da0-4c58-a06d-96f80579c476">';
    echo '<p class="description">只需填写 Webhook 地址的后缀部分（UUID格式的36位字符）<br>'
        . '<strong style="color:#d63638;">不要填写完整URL！</strong> 插件会自动添加前缀。<br>'
        . '获取位置：飞书群 > 添加机器人 > Webhook地址中最后一个斜杠(/)后的部分</p>';
}

function feishu_order_notify_statuses_render() {
    if (!function_exists('wc_get_order_statuses')) {
        echo '<p style="color:#d63638;">需要安装并激活 WooCommerce 插件</p>';
        return;
    }
    
    $options = get_option(FEISHU_NOTIFY_OPTION_NAME);
    $selected = isset($options['statuses']) ? (array)$options['statuses'] : ['pending','on-hold','processing','completed'];
    $statuses = wc_get_order_statuses();
    
    echo '<div style="display:grid; grid-template-columns:repeat(3, 1fr); max-width:600px;">';
    foreach ($statuses as $key => $label) {
        $slug = str_replace('wc-', '', $key);
        $checked = in_array($slug, $selected) ? 'checked' : '';
        echo '<label style="display:block; margin:5px 0;"><input type="checkbox" name="'.esc_attr(FEISHU_NOTIFY_OPTION_NAME).'[statuses][]" value="'.esc_attr($slug).'" '.esc_attr($checked).'> '.esc_html($label).'</label>';
    }
    echo '</div>';
    echo '<p class="description">勾选需要在飞书接收通知的订单状态</p>';
}

function feishu_order_notify_options_page() {
    $saved = false;
    $tested = false;
    $test_error = '';

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['feishu_action'])) {
        if ($_POST['feishu_action'] === 'save' && check_admin_referer('feishu_order_notify_save_action')) {
            // ==== 安全修复：添加 wp_unslash() ====
            $raw = isset($_POST[FEISHU_NOTIFY_OPTION_NAME]) 
                ? [
                    'webhook_key' => sanitize_text_field(wp_unslash($_POST[FEISHU_NOTIFY_OPTION_NAME]['webhook_key'] ?? '')),
                    'statuses' => isset($_POST[FEISHU_NOTIFY_OPTION_NAME]['statuses']) 
                        ? array_map('sanitize_text_field', (array)wp_unslash($_POST[FEISHU_NOTIFY_OPTION_NAME]['statuses'])) 
                        : []
                ]
                : [];
            // ==== 安全修复结束 ====
            
            $webhook_key = $raw['webhook_key'] ?? '';
            if (strpos($webhook_key, FEISHU_NOTIFY_PREFIX) === 0) {
                $webhook_key = str_replace(FEISHU_NOTIFY_PREFIX, '', $webhook_key);
            }
            
            $clean = [
                'webhook_key' => $webhook_key,
                'statuses' => $raw['statuses'] ?? []
            ];
            update_option(FEISHU_NOTIFY_OPTION_NAME, $clean);
            $saved = true;
        }
        if ($_POST['feishu_action'] === 'test' && check_admin_referer('feishu_order_notify_test_action')) {
            $test_result = feishu_order_notify_send_test();
            if ($test_result === true) {
                $tested = true;
            } else {
                $test_error = is_string($test_result) ? $test_result : '未知错误';
            }
        }
    }

    echo '<div class="wrap" style="max-width:1200px; margin:20px auto;">';
    if ($saved) {
        echo '<div class="notice notice-success is-dismissible"><p>✅ 设置已保存</p></div>';
    }
    if ($tested) {
        echo '<div class="notice notice-success is-dismissible"><p>📨 测试消息已发送，请前往飞书群查看</p></div>';
    }
    if (!empty($test_error)) {
        echo '<div class="notice notice-error is-dismissible"><p>❌ 测试失败: '.esc_html($test_error).'</p></div>';
    }

    echo '<h1>飞书订单通知设置</h1>';
    echo '<form method="post">';
    settings_fields(FEISHU_NOTIFY_OPTION_GROUP);
    do_settings_sections('feishu_order_notify');
    wp_nonce_field('feishu_order_notify_save_action');
    echo '<input type="hidden" name="feishu_action" value="save">';
    submit_button('保存设置');
    echo '</form>';

    echo '<form method="post" style="margin-top:12px;">';
    wp_nonce_field('feishu_order_notify_test_action');
    echo '<input type="hidden" name="feishu_action" value="test">';
    submit_button('发送测试消息', 'secondary');
    echo '</form>';

    echo '<h2>📋 最近通知记录</h2>';
    echo '<div style="overflow-x:auto;">';
    echo '<table class="widefat fixed striped" style="width:100%; table-layout: fixed;">';
    echo '<thead><tr>'; 
    echo '<th style="text-align:center; width:140px;">时间</th>';
    echo '<th style="text-align:center; width:140px;">发送状态</th>';
    echo '<th style="text-align:center; width:100px;">订单状态</th>';
    echo '<th style="text-align:center; width:120px;">客户</th>';
    echo '<th style="text-align:center; width:180px;">邮箱</th>';
    echo '<th style="text-align:center; width:100px;">金额</th>';
    echo '<th style="text-align:center; width:80px;">订单ID</th>';
    echo '<th style="text-align:center; min-width:400px;">产品明细</th>';
    echo '</tr></thead><tbody>';
    $logs = get_transient(FEISHU_NOTIFY_LOG_TRANSIENT) ?: [];
    if (empty($logs)) {
        echo '<tr><td colspan="8" style="text-align:center;">暂无记录</td></tr>';
    } else {
        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td style="text-align:center;">'.esc_html($log['time']).'</td>';
            
            $status_class = strpos($log['status'], '✅') !== false ? 'status-success' : 'status-error';
            echo '<td class="'.esc_attr($status_class).'" style="word-wrap:break-word; white-space:normal;">'.esc_html($log['status']).'</td>';
            
            echo '<td style="text-align:center;">'.esc_html($log['order_status']).'</td>';
            echo '<td style="text-align:center;">'.esc_html($log['customer']).'</td>';
            echo '<td style="text-align:center;">'.esc_html($log['email']).'</td>';
            echo '<td style="text-align:center;">'.esc_html($log['total']).'</td>';
            echo '<td style="text-align:center;">#'.esc_html($log['order_id']).'</td>';
            echo '<td style="white-space:pre-wrap; text-align:left; word-break: break-word;">'.esc_html($log['items']).'</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '</div>';
    
    $options = get_option(FEISHU_NOTIFY_OPTION_NAME);
    $webhook_key = $options['webhook_key'] ?? '';
    
    echo '<div style="margin-top:30px; background:#f5f5f5; padding:15px; border-left:4px solid #0073aa;">';
    echo '<h3>调试信息</h3>';
    echo '<p>当前配置的 Webhook 后缀: ';
    if (!empty($webhook_key)) {
        echo '<code>'.esc_html($webhook_key).'</code>';
        echo '<br>完整 URL: <code>'.esc_html(FEISHU_NOTIFY_PREFIX).esc_html($webhook_key).'</code>';
    } else {
        echo '<em>未配置</em>';
    }
    echo '</p>';
    
    if (!empty($webhook_key)) {
        if (strpos($webhook_key, 'http') === 0) {
            echo '<div class="notice notice-error"><p>⚠️ 检测到您可能填写了完整URL！插件只需要后缀部分。</p>'
                . '<p>建议修改为: <code>'.esc_html(preg_replace('/^.*\/([a-f0-9\-]{36})$/i', '$1', $webhook_key)).'</code></p></div>';
        }
    }
    
    echo '<p>获取正确 Webhook 后缀的方法：</p>';
    echo '<ol>';
    echo '<li>在飞书群中点击右上角群设置</li>';
    echo '<li>选择「群机器人」>「添加机器人」</li>';
    echo '<li>选择「自定义机器人」并设置名称</li>';
    echo '<li>复制生成的 Webhook 地址中最后一个斜杠(/)后的部分</li>';
    echo '<li><strong>示例后缀</strong>: ca95b85c-2da0-4c58-a06d-96f80579c476</li>';
    echo '</ol>';
    echo '</div>';
    echo '</div>';
    
    echo '<style>
        .status-error {
            color: #d63638;
            background-color: #f8eaea;
        }
        .status-success {
            color: #00a32a;
            background-color: #edfaef;
        }
        table.widefat td {
            vertical-align: top;
            padding: 8px 10px;
        }
        table.widefat th {
            padding: 10px;
            background-color: #f0f0f1;
        }
        
        /* 产品明细列自动换行 */
        table.widefat td:nth-child(8) {
            white-space: pre-wrap;
            word-break: break-word;
        }
        
        /* 响应式设计 */
        @media screen and (max-width: 1200px) {
            .wrap {
                max-width: 95%;
                margin: 20px auto;
            }
        }
        
        @media screen and (max-width: 960px) {
            table.widefat {
                font-size: 13px;
            }
            table.widefat th,
            table.widefat td {
                padding: 6px 8px;
            }
        }
        
        @media screen and (max-width: 782px) {
            .wrap {
                max-width: 100%;
                padding: 0 15px;
            }
            
            table.widefat {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
                -webkit-overflow-scrolling: touch;
            }
            
            table.widefat thead {
                display: none;
            }
            
            table.widefat tbody {
                display: block;
                width: 100%;
            }
            
            table.widefat tr {
                display: grid;
                grid-template-columns: 1fr 1fr;
                grid-gap: 10px;
                border: 1px solid #ddd;
                margin-bottom: 15px;
                padding: 10px;
            }
            
            table.widefat td {
                display: block;
                padding: 5px 8px;
                border: none;
                border-bottom: 1px solid #eee;
                white-space: normal;
                text-align: left !important;
                width: auto !important;
            }
            
            table.widefat td::before {
                content: attr(data-label);
                display: block;
                font-weight: bold;
                color: #2271b1;
                margin-bottom: 3px;
            }
            
            table.widefat td[data-label] {
                display: block;
            }
            
            /* 移动端产品明细全宽显示 */
            table.widefat td:nth-child(8) {
                grid-column: span 2;
            }
        }
    </style>';
    
    // 为移动端添加数据标签
    echo '<script>
    jQuery(document).ready(function($) {
        // 为移动端表格单元格添加数据标签
        if (window.matchMedia("(max-width: 782px)").matches) {
            $("table.widefat th").each(function() {
                var label = $(this).text().trim();
                var index = $(this).index();
                $("table.widefat td:nth-child(" + (index + 1) + ")").attr("data-label", label);
            });
        }
    });
    </script>';
}

add_action('woocommerce_order_status_changed', 'feishu_order_notify_status_hook', 10, 4);
function feishu_order_notify_status_hook($order_id, $from, $to, $order) {
    // 确保WooCommerce可用
    if (!function_exists('wc_get_order_statuses')) return;
    
    $options = get_option(FEISHU_NOTIFY_OPTION_NAME);
    $selected = isset($options['statuses']) ? (array)$options['statuses'] : [];
    if (in_array($to, $selected)) {
        feishu_order_notify_send($order_id);
    }
}

function feishu_order_notify_send($order_id) {
    $options = get_option(FEISHU_NOTIFY_OPTION_NAME);
    $key = trim($options['webhook_key'] ?? '');
    
    // 自动修正：如果用户填写了完整URL，提取后缀部分
    if (strpos($key, FEISHU_NOTIFY_PREFIX) === 0) {
        $key = str_replace(FEISHU_NOTIFY_PREFIX, '', $key);
    }
    
    if (empty($key)) return;
    
    $webhook_url = FEISHU_NOTIFY_PREFIX . $key;
    
    // 确保WooCommerce可用
    if (!function_exists('wc_get_order')) return;
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    // 获取客户全名
    $first_name = $order->get_billing_first_name();
    $last_name = $order->get_billing_last_name();
    $customer_name = trim($first_name . ' ' . $last_name);
    
    $data = [
        'order_status' => wc_get_order_status_name($order->get_status()),
        'customer' => $customer_name ?: '未提供',
        'email' => $order->get_billing_email() ?: '未提供',
        'total' => $order->get_total() . ' ' . get_woocommerce_currency(),
        'order_id' => $order_id
    ];
    
    $items_text = '';
    foreach ($order->get_items() as $item) {
        $items_text .= '- ' . $item->get_name() . ' x' . $item->get_quantity() . "\n";
    }
    $data['items'] = $items_text;

    $text = "📦 订单状态：{$data['order_status']}\n";
    $text .= "时间: " . date_i18n('Y-m-d H:i:s') . "\n";
    $text .= "客户: {$data['customer']}\n";
    $text .= "邮箱: {$data['email']}\n";
    $text .= "金额: {$data['total']}\n";
    $text .= "订单ID: #{$data['order_id']}\n\n";
    $text .= "📦 产品明细:\n" . $data['items'];

    $response = wp_remote_post($webhook_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode(['msg_type' => 'text', 'content' => ['text' => $text]]),
        'timeout' => 15,
    ]);

    $status = '✅ 成功';
    $error_details = '';
    
    if (is_wp_error($response)) {
        $status = '❌ 失败: ' . $response->get_error_message();
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);
        
        if ($status_code !== 200) {
            $status = '❌ HTTP 错误: ' . $status_code;
            $error_details = $body;
        } elseif (isset($decoded_body['code']) && $decoded_body['code'] !== 0) {
            $error_msg = $decoded_body['msg'] ?? '未知错误';
            $status = '❌ 失败: ' . $error_msg;
            $error_details = $body;
        }
    }

    $logs = get_transient(FEISHU_NOTIFY_LOG_TRANSIENT) ?: [];
    
    // 添加错误详情到日志
    $log_data = array_merge([
        'time' => date_i18n('Y-m-d H:i:s'),
        'status' => $status
    ], $data);
    
    if (!empty($error_details)) {
        $log_data['error_details'] = $error_details;
    }
    
    array_unshift($logs, $log_data);
    set_transient(FEISHU_NOTIFY_LOG_TRANSIENT, array_slice($logs, 0, FEISHU_NOTIFY_LOG_LIMIT), 3 * DAY_IN_SECONDS);
}

function feishu_order_notify_send_test() {
    $options = get_option(FEISHU_NOTIFY_OPTION_NAME);
    $key = trim($options['webhook_key'] ?? '');
    
    // 自动修正：如果用户填写了完整URL，提取后缀部分
    if (strpos($key, FEISHU_NOTIFY_PREFIX) === 0) {
        $key = str_replace(FEISHU_NOTIFY_PREFIX, '', $key);
    } elseif (preg_match('/\/hook\/([a-f0-9\-]{36})$/', $key, $matches)) {
        $key = $matches[1];
    }
    
    if (empty($key)) {
        return "Webhook Key 未填写";
    }
    
    // 验证格式（简单的UUID格式检查）
    if (!preg_match('/^[a-f0-9\-]{36}$/i', $key)) {
        return "Webhook Key 格式无效，应为36位字符的UUID格式";
    }
    
    $webhook_url = FEISHU_NOTIFY_PREFIX . $key;
    $text = "📢 测试消息：您的飞书 Webhook 已成功配置！\n时间: " . date_i18n('Y-m-d H:i:s');
    
    $response = wp_remote_post($webhook_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode(['msg_type' => 'text', 'content' => ['text' => $text]]),
        'timeout' => 15,
    ]);
    
    if (is_wp_error($response)) {
        return "请求失败: " . $response->get_error_message();
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded_body = json_decode($body, true);
    
    if ($status_code !== 200) {
        return "HTTP 错误: {$status_code}";
    }
    
    if (isset($decoded_body['code']) && $decoded_body['code'] !== 0) {
        $error_code = $decoded_body['code'];
        $error_msg = $decoded_body['msg'] ?? '未知错误';
        
        // 提供更友好的错误提示
        if ($error_code == 19021) {
            return "Webhook 后缀无效 (错误码: 19021)。请检查：\n"
                 . "1. 是否完整复制了后缀\n"
                 . "2. 后缀是否过期\n"
                 . "3. 飞书机器人是否被删除";
        }
        
        return "飞书返回错误: [{$error_code}] {$error_msg}";
    }
    
    return true;
}

add_filter(
    'plugin_action_links_' . plugin_basename(__FILE__),
    function($links) {
        $settings_link = '<a href="options-general.php?page=feishu_order_notify">设置</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
);