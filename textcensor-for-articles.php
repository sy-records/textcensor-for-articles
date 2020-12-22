<?php
/*
Plugin Name: TextCensor For Articles
Plugin URI: https://github.com/sy-records/textcensor-for-articles
Description: 基于百度文本审核技术来提供WordPress文章内容审核。
Version: 1.1.1
Author: 沈唁
Author URI: https://qq52o.me
License: Apache 2.0
*/

// init plugin
add_action('admin_init', 'luffy_tcfa_submit_default_options');
function luffy_tcfa_submit_default_options()
{
    // 获取选项
    $default = get_option('TextCensorForArticles');
    if ($default == '') {
        // 设置默认数据
        $default = array(
            'app_id' => '',
            'api_key' => '',
            'secret_key' => '',
            'delete' => '',
        );
        //更新选项
        update_option('TextCensorForArticles', $default);
    }
}

// stop plugin
function luffy_tcfa_stop_option()
{
    $option = get_option('TextCensorForArticles');
    if ($option['delete']) {
        delete_option("TextCensorForArticles");
    }
}

register_deactivation_hook(__FILE__, 'luffy_tcfa_stop_option');

// setting plugin
add_action('admin_menu', 'luffy_tcfa_submit_menu');
function luffy_tcfa_submit_menu()
{
    add_submenu_page(
        'options-general.php',
        '文章内容审核设置',
        '文章内容审核设置',
        'manage_options',
        'TextCensor_For_Articles',
        'luffy_tcfa_submit_options'
    );
}

// add setting button
function luffy_tcfa_plugin_action_links($links, $file)
{
    if ($file == plugin_basename(dirname(__FILE__) . '/textcensor-for-articles.php')) {
        $links[] = '<a href="options-general.php?page=TextCensor_For_Articles">设置</a>';
    }
    return $links;
}

add_filter('plugin_action_links', 'luffy_tcfa_plugin_action_links', 10, 2);

// setting page
function luffy_tcfa_submit_options()
{
    //保存数据
    if (isset($_POST['luffy_tcfa_submit'])) {
        if (!current_user_can('level_10')) {
            echo '<div class="error" id="message"><p>暂无权限操作</p></div>';
            return;
        }
        $nonce = $_REQUEST['_luffy_tcfa_nonce'];
        if (!wp_verify_nonce($nonce, 'luffyTcfaSubmit')) {
            echo '<div class="error" id="message"><p>非法操作</p></div>';
            return;
        }

        $app_id = sanitize_text_field($_POST['app_id']);
        $api_key = sanitize_text_field($_POST['api_key']);
        $secret_key = sanitize_text_field($_POST['secret_key']);
        $delete = isset($_POST['delete']) ? sanitize_text_field($_POST['delete']) : false;

        $check_status = luffy_tcfa_submit_check($app_id, $api_key, $secret_key);
        if ($check_status) {
            echo '<div class="error" id="message"><p>获取Access Token失败，请检查参数</p></div>';
        } else {
            $tcfaOption = array(
                'app_id' => $app_id,
                'api_key' => $api_key,
                'secret_key' => $secret_key,
                'delete' => $delete,
            );
            $res = update_option('TextCensorForArticles', $tcfaOption);//更新选项
            if ($res) {
                $updated = '设置成功！';
            } else {
                $updated = '设置失败或未更新选项！';
            }
            echo '<div class="updated" id="message"><p>' . $updated . '</p></div>';
        }
    }
    // //获取选项
    $option = get_option('TextCensorForArticles');
    $delete = $option['delete'] !== false ? 'checked="checked"' : '';
    echo '<div class="wrap">';
    echo '<h2>文章内容审核设置</h2>';
    echo '<form method="post">';
    echo '<table class="form-table">';
    echo '<tr valign="top">';
    echo '<td><input class="all-options" type="hidden" name="_luffy_tcfa_nonce" value="' . wp_create_nonce(
            'luffyTcfaSubmit'
        ) . '"></td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">AppID</th>';
    echo '<td><input class="all-options" type="text" name="app_id" value="' . $option['app_id'] . '" /></td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">API Key</th>';
    echo '<td><input class="all-options" type="text" name="api_key" value="' . $option['api_key'] . '" /></td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">Secret Key</th>';
    echo '<td><input class="all-options" type="text" name="secret_key" value="' . $option['secret_key'] . '" /></td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">是否删除配置信息</th>';
    echo '<td><label><input value="true" type="checkbox" name="delete" ' . $delete . '> 勾选后停用插件时会删除保存的配置信息</label></td>';
    echo '</tr>';
    echo '</table>';
    echo '<p class="submit">';
    echo '<input type="submit" name="luffy_tcfa_submit" id="submit" class="button button-primary" value="保存更改" />';
    echo '</p>';
    echo '</form>';
    echo '<p><strong>使用提示</strong>：<br>
	1. AppID、API Key、Secret Key在百度 AI 控制台的 <a target="_blank" href="https://console.bce.baidu.com/ai/?fromai=1#/ai/antiporn/app/list">产品服务 / 内容审核 - 应用列表</a> 创建应用后获取；<br>
	2. 百度有默认审核策略，如果误杀严重，请进入 <a target="_blank" href="https://ai.baidu.com/censoring#/strategylist">内容审核平台创建自定义规则</a> 进行修改策略；<br>
	3. 如有问题请至 <a target="_blank" href="https://github.com/sy-records/textcensor-for-articles">Github</a> 查看使用说明或提交 <a target="_blank" href="https://github.com/sy-records/textcensor-for-articles/issues">Issue</a>。
	</p>';
    echo '</div>';
}

function luffy_tcfa_submit_check($appId, $apiKey, $secretKey)
{
    if (!empty($appId) && !empty($apiKey) && !empty($secretKey)) {
        if (!class_exists("\Luffy\TextCensor\AipBase")) {
            require_once dirname(__FILE__) . '/src/AipBase.php';
        }
        $client = new \Luffy\TextCensor\AipBase($appId, $apiKey, $secretKey);
        $response = $client->auth();
        if (isset($response['error']) || isset($response['error_description'])) {
            return true;
        }
    }
    return false;
}

function luffy_tcfa_publish_post($post_ID)
{
    $post = get_post($post_ID);
    if ($post->post_type == "post" || $post->post_type == "page") {
        $option = get_option('TextCensorForArticles');
        if (!class_exists("\Luffy\TextCensor\AipBase")) {
            require_once dirname(__FILE__) . '/src/AipBase.php';
        }
        $client = new \Luffy\TextCensor\AipBase($option['app_id'], $option['api_key'], $option['secret_key']);
        $res = $client->textCensorUserDefined($post->post_content);
        // 1.合规，2.不合规，3.疑似，4.审核失败
        if (in_array($res['conclusionType'], [2, 3])) {
            $msg = "文章内容";
            foreach ($res['data'] as $item) {
                $msg .= $item['msg'] . "：";
                foreach ($item['hits'] as $hitsItem) {
                    $msg .= $hitsItem['words'][0] . "；";
                }
            }
            set_transient("luffy_tcfa_status", $msg);
        }
    }
    return $post_ID;
}
add_filter('publish_post', 'luffy_tcfa_publish_post');
add_filter('publish_page', 'luffy_tcfa_publish_post');

add_action('admin_notices', 'luffy_tcfa_status_notices');
function luffy_tcfa_status_notices()
{
    $status = get_transient("luffy_tcfa_status");
    if (!empty($status)) {
        echo '<div class="notice notice-error is-dismissible"><p>' . $status . '</p></div>';
        delete_transient("luffy_tcfa_status");
    }
}
