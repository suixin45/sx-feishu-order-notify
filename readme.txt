=== Feishu Order Notify ===
Contributors: suixin45
Tags: woocommerce, feishu, notification, order, webhook
Requires at least: 5.6
Tested up to: 6.8
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

将 WooCommerce 订单状态通过 Feishu Webhook 发送到指定群聊

== Description ==
支持功能：
- 后台配置 Webhook 地址后缀
- 自定义监听订单状态
- 实时产品明细展示
- 消息发送记录追踪
- 一键测试消息功能
- 移动端响应式界面

== Installation ==
1. 上传插件到 `/wp-content/plugins/` 目录
2. 在 WordPress 后台启用插件
3. 进入 设置 > Feishu 订单通知
4. 填写飞书机器人 Webhook 后缀（36位UUID）
5. 选择要监听的订单状态
6. 点击"发送测试消息"验证配置

== Screenshots ==
1. 后台设置界面 - 配置Webhook后缀和订单状态
2. 飞书消息接收效果 - 显示订单详情的消息示例
3. 通知记录表格 - 包含发送状态和时间戳的日志

== Changelog ==
= 1.0 =
* 首次发布版本
* 支持订单状态实时通知
* 内置消息发送日志系统
* 响应式管理界面设计