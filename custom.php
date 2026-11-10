<?php
$my_texts = $texts;
require_once __DIR__ . "/App/vars.php";
require_once __DIR__ . "/App/Utils/db_tools.php";
require_once __DIR__ . "/App/Utils/pure.php";
require_once __DIR__ . "/App/functions/renewal_confirm.php";

$BOT_CONFIG = json_decode(file_get_contents($BOT_CONFIG_PATH), true);
function get_users_usage($user_id)
{
    global $sql, $my_texts;
    $services = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$user_id'");
    if ($services->num_rows > 0) {
        $total_traffic_bought = 0;
        $total_traffic_used = 0;
        $users_not_found_count = 0;

        while ($row = $services->fetch_assoc()) {
            $config_name = $row['code'] . '_' . $user_id;
            $config_location = $row['location'];

            $panel = get_panel_by_name($config_location);
            $getUserInfoRes = getUserInfo($config_name, get_marzban_panel_token($config_location), $panel['login_link']);
            if ($getUserInfoRes[0] == false) {
                alert($my_texts['error_show_service__token_is_incorrect']);
                return null;
                break;
            } else if ($getUserInfoRes[1] == false) {
                // alert($my_texts['error_show_service__user_not_found']);
                $users_not_found_count = $users_not_found_count + 1;
                continue;
            } else {
                $marzban_res = $getUserInfoRes[2];
                if ($marzban_res['username'] == $config_name) {
                    if ($marzban_res['status'] == 'active') {
                        $total_traffic_bought = $total_traffic_bought + $marzban_res['data_limit'];
                        $total_traffic_used = $total_traffic_used + $marzban_res['used_traffic'];
                    }
                }
            };
        }

        $total_traffic_bought = Conversion($total_traffic_bought, "GB");
        $total_traffic_used = Conversion($total_traffic_used, "GB");

        return [
            "total_traffic_bought" => $total_traffic_bought,
            "total_traffic_used" => $total_traffic_used,
            "users_not_found_count" => $users_not_found_count
        ];
    } else {
        return null;
    }
}

function modify_bot_config()
{
    global $BOT_CONFIG, $BOT_CONFIG_PATH;
    $bot_config_data_json = json_encode($BOT_CONFIG, JSON_PRETTY_PRINT);
    file_put_contents($BOT_CONFIG_PATH, $bot_config_data_json);
    if (function_exists('apcu_delete')) apcu_delete('botpanel_config');
};

function change_account_status($text, $from_id)
{
    global $BOT_CONFIG, $texts;
    function marzban_change_status($username, $new_status, $token, $url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . "/api/user/$username");
        // curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token, 'Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('status' => $new_status)));
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    };

    function change()
    {
        global $from_id, $user, $text, $sql, $texts, $my_texts, $start_key, $bot_management_keyboard, $config;
        if ($text == $texts['account_status_changer_button']) {
            step('account_status_changer_service_get_service_name');
            $_keyboard = null;
            if ($from_id == $config['dev'] or in_array($from_id, get_admin_ids())) {
                $_keyboard = $back_to_manage_users; 
            } else {
                $_keyboard_keys = [[['text' => $texts['back_to_menu_button']]]];
                $_keyboard = json_encode(['keyboard' => $_keyboard_keys, 'resize_keyboard' => true]);
            }
            sendMessage($from_id, $my_texts['account_status_changer_service_config_name'], $_keyboard);
        } elseif ($user['step'] == 'account_status_changer_service_get_service_name') {
            if ($from_id == $config['dev'] or in_array($from_id, get_admin_ids())) {
                $_return_keyboard = $bot_management_keyboard;
                $config_base_name = $text;
                $config_location_obj = $sql->query("SELECT `location`, `from_id` FROM `orders` WHERE `code` = '$config_base_name' AND `from_id` = '$from_id'");

                // $t = json_encode($config_location_obj, 448);
                // sendMessage($from_id, "config_location_obj : $t");

                $config_found_count = 0;
                while ($row = $config_location_obj->fetch_assoc()) {
                    $config_found_count = $config_found_count + 1;
                    $last_row = $row;

                    // $t = json_encode($row, 448);
                    // sendMessage($from_id, "row : $t");

                }

                if ($config_found_count == 1) {
                    if ($last_row['from_id'] == $from_id) {
                        $config_base_name = $text;
                        $config_user_id = $from_id;
                    } else {
                        step('account_status_changer_service_get_service_name');
                        sendMessage($from_id, $my_texts['account_status_changer_service_config_not_found'] . "(code 1)");
                        exit();
                    }
                } else {
                    $config_parts = explode("_", $text);

                    // $t = json_encode($config_parts, 448);
                    // sendMessage($from_id, "config_parts : $t");

                    // $t = json_encode(count($config_parts), 448);
                    // sendMessage($from_id, "count($config_parts) : $t");

                    if (count($config_parts) == 1) {
                        $config_base_name = $text;
                        $config_user_id = $from_id;
                    } else {
                        $config_base_name = implode("_", array_slice($config_parts, 0, -1));
                        $config_user_id = end($config_parts);
                    }
                }
            } else {
                $_return_keyboard = $start_key;
                $config_base_name = $text;
                $config_user_id = $from_id;
            }
            $config_name = $config_base_name . '_' . $config_user_id;

            // $t = json_encode($config_base_name, 448);
            // sendMessage($from_id, "config_base_name : $t");
            // $t = json_encode($config_user_id, 448);
            // sendMessage($from_id, "config_user_id : $t");
            // $t = json_encode($config_name, 448);
            // sendMessage($from_id, "config_name : $t");

            $mysql_config_array = $sql->query("SELECT `location`,`from_id` FROM `orders` WHERE `code` = '$config_base_name' AND `from_id` = '$from_id'");
            $config_array = null;
            while ($row = $mysql_config_array->fetch_assoc()) {
                if ($row['from_id'] == $config_user_id) {
                    $config_array = $row;
                }
            }
            if (isset($config_array)) {

                // $t = json_encode($config_array['from_id'], 448);
                // sendMessage($from_id, "test : $t");

                if ($config_array['from_id'] != $config_user_id) {
                    step('account_status_changer_service_get_service_name');
                    sendMessage($from_id, $my_texts['account_status_changer_service_config_not_found'] . "(code 2)");
                    exit();
                }
                $config_location_name = $config_array['location'];
                sendMessage($from_id, $my_texts['account_status_changer_service_config_found'],);

                $panel_info = get_panel_by_name($config_location_name);
                $panel_token = get_marzban_panel_token($config_location_name);
                $panel_url = $panel_info['login_link'];

                $user_config_info = getUserInfo($config_name, $panel_token, $panel_url);
                if ($user_config_info[0] == false) {
                    step('none');
                    alert($my_texts['error_show_service__token_is_incorrect']);
                    exit();
                } else if ($user_config_info[1] == false) {
                    step('none');
                    alert($my_texts['error_show_service__user_not_found']);
                    exit();
                }

                $user_config_status = $user_config_info[2]['status'];

                if ($user_config_status == "active") {
                    $new_status = 'disabled';
                } elseif ($user_config_status == "disabled") {
                    $new_status = 'active';
                } else {
                    $new_status = null;
                }
                if (isset($new_status)) {
                    $new_marzban_change_status_response = json_decode(marzban_change_status($config_name, $new_status, $panel_token, $panel_url), true);
                    if (isset($new_marzban_change_status_response['username'])) {
                        step('none');

                        if ($new_marzban_change_status_response['status'] == "active") {
                            sendMessage($from_id, $texts['account_status_changer_service_successfully_enabled'], $_return_keyboard);
                        } elseif ($new_marzban_change_status_response['status'] == "disabled") {
                            sendMessage($from_id, $texts['account_status_changer_service_successfully_disabled'], $_return_keyboard);
                        }
                    } else {
                        step('none');
                        sendMessage($from_id, $texts['account_status_changer_service_failed_to_change_status'], $_return_keyboard);
                    }
                } else {
                    step('none');
                    sendMessage($from_id, $texts['account_status_changer_unathorized'], $_return_keyboard);
                }
            } else {
                step('account_status_changer_service_get_service_name');
                sendMessage($from_id, $my_texts['account_status_changer_service_config_not_found'] . "(code 3)");
            }
        }
    }
    global $config, $sql;
    if ($from_id == $config['dev'] or in_array($from_id, get_admin_ids())) {
        change();
    } else {
        $row = $sql->query("SELECT `show_account_status_changer_btn` FROM `settings` LIMIT 1")->fetch_assoc();
        $charge_btn_status = $row['show_account_status_changer_btn'] ?? 'active';
        if ($charge_btn_status === 'active') {
            change();
        }
    }
}


function reset_panel_panel_token($panel_name, $panel_login_address, $panel_username, $panel_password)
{
    global $sql;
    $login_result = loginPanel($panel_login_address, $panel_username, $panel_password);
    if (isset($login_result["access_token"])) {
        $new_token = $login_result["access_token"];
        db_exec($sql, "UPDATE `panels` SET `token` = ? WHERE `name` = ?", "ss", array($new_token, $panel_name));
        return $new_token;
    } else {
        return false;
    };
}


function read_renewal_json($user_id)
{
    $res = read_temp_file("renewal-service-$user_id.json", true);
    return $res[0];
}



function write_renewal_json($user_id, $user_array)
{
    write_temp_file($user_array, "renewal-service-$user_id.json", true);
}



function renewal_service($text, $from_id)
{
    global $user, $sql, $texts, $start_key, $confirm_service, $message_id, $config, $my_texts, $first_name, $maintainer_telegram_id_number;

    // sendMessage($from_id, "text : $text",);
    // $curent_step = $user['step'];
    // sendMessage($from_id, "old step : $curent_step",);

    if ($text == '➕ تمدید سرویس') {
        step('renewal_service_get_service_name');
        $_renewal_keyboard_keys = [[['text' => "⬅️ بازگشت به صفحه اصلی"]]];
        $_renewal_keyboard = json_encode(['keyboard' => $_renewal_keyboard_keys, 'resize_keyboard' => true]);
        sendMessage($from_id, $my_texts['renewal_service_config_name'], $_renewal_keyboard);
    } elseif ($user['step'] == 'renewal_service_get_service_name') {
        $config_base_name = trim((string) $text);
        if ($config_base_name === '') {
            $_renewal_keyboard = json_encode(['keyboard' => [[['text' => "⬅️ بازگشت به صفحه اصلی"]]], 'resize_keyboard' => true]);
            sendMessage($from_id, $my_texts['renewal_service_config_name'], $_renewal_keyboard);
            return;
        }

        $order = db_row($sql, "SELECT `location`, `type` FROM `orders` WHERE `code` = ? AND `from_id` = ? LIMIT 1", "ss", array($config_base_name, $from_id));
        if (!$order) {
            step('renewal_service_get_service_name');
            sendMessage($from_id, $my_texts['renewal_service_config_not_found']);
            return;
        }
        if (isset($order['type']) && $order['type'] !== 'marzban') {
            Logger::warning('renewal rejected: order is not marzban', array('from_id' => $from_id, 'code' => $config_base_name, 'type' => $order['type']));
            step('none');
            sendMessage($from_id, sprintf($texts['renew_error'], 2), $start_key);
            return;
        }

        sendMessage($from_id, $my_texts['renewal_service_config_found']);
        write_renewal_json($from_id, array(
            'config_base_name' => $config_base_name,
            'config_name' => $config_base_name . '_' . $from_id,
            'old_server' => $order['location']
        ));

        // Only the panel that actually hosts the service can be renewed;
        // a real move would have to create the user on the target first.
        $servers = json_encode(['keyboard' => [
            [['text' => $order['location']]],
            [['text' => '🔙 بازگشت']]
        ], 'resize_keyboard' => true]);
        step('renewal_service_choose_server');
        sendMessage($from_id, $my_texts['renewal_service_server_selection'], $servers);
    } elseif ($user['step'] == 'renewal_service_choose_server') {
        $renewal_data = read_renewal_json($from_id);
        if (!is_array($renewal_data) || empty($renewal_data['old_server'])) {
            Logger::warning('renewal state missing at choose_server', array('from_id' => $from_id));
            step('none');
            sendMessage($from_id, $my_texts['renewal_service_config_not_found'], $start_key);
            return;
        }
        if ($text === '🔙 بازگشت') {
            step('renewal_service_get_service_name');
            $_renewal_keyboard = json_encode(['keyboard' => [[['text' => "⬅️ بازگشت به صفحه اصلی"]]], 'resize_keyboard' => true]);
            sendMessage($from_id, $my_texts['renewal_service_config_name'], $_renewal_keyboard);
            exit(0); // handled here, so index.php's later back handler cannot reset it
        }
        if ($text !== $renewal_data['old_server']) {
            $servers = json_encode(['keyboard' => [
                [['text' => $renewal_data['old_server']]],
                [['text' => '🔙 بازگشت']]
            ], 'resize_keyboard' => true]);
            sendMessage($from_id, $texts['choice_error'] . "\n\n" . $my_texts['renewal_service_server_selection'], $servers);
            return;
        }
        $renewal_data['new_server'] = $text;
        write_renewal_json($from_id, $renewal_data);

        $plan_rows = get_active_categories();
        if (count($plan_rows) === 0) {
            step('none');
            sendMessage($from_id, $texts['inactive_buy_service'], $start_key);
            return;
        }
        $plan = array();
        foreach ($plan_rows as $row) {
            $plan[] = ['text' => $row['name']];
        }
        $plan = array_chunk($plan, 2);
        $plan[] = [['text' => '🔙 بازگشت']];
        $plan = json_encode(['keyboard' => $plan, 'resize_keyboard' => true]);
        step('renewal_service_select_plan');
        sendMessage($from_id, $texts['select_plan'], $plan);
    } elseif ($user['step'] == 'renewal_service_select_plan') {
        $plan_name = trim((string) $text);
        $renewal_data = read_renewal_json($from_id);
        if (!is_array($renewal_data) || empty($renewal_data['old_server']) || empty($renewal_data['new_server']) || empty($renewal_data['config_name'])) {
            Logger::warning('renewal state missing at select_plan', array('from_id' => $from_id));
            step('none');
            sendMessage($from_id, $my_texts['renewal_service_config_not_found'], $start_key);
            return;
        }
        if ($plan_name === '🔙 بازگشت') {
            $servers = json_encode(['keyboard' => [
                [['text' => $renewal_data['old_server']]],
                [['text' => '🔙 بازگشت']]
            ], 'resize_keyboard' => true]);
            step('renewal_service_choose_server');
            sendMessage($from_id, $my_texts['renewal_service_server_selection'], $servers);
            exit(0);
        }

        // One row, active only. Typed names cannot bypass the active list.
        $plan_row = db_row($sql, "SELECT * FROM `category` WHERE `name` = ? AND `status` = 'active' LIMIT 1", "s", array($plan_name));
        if (!$plan_row) {
            sendMessage($from_id, $texts['choice_error']);
            return; // stay on this step, unlike today's silent no-op
        }
        $price = isset($plan_row['price']) ? $plan_row['price'] : 0;
        if ($user['coin'] < $price) {
            step('none');
            sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
            return;
        }

        $renewal_data['new_plan'] = $plan_name;
        $renewal_data['new_plan_price'] = $price;
        $renewal_data['new_plan_limit'] = isset($plan_row['limit']) ? $plan_row['limit'] : 0;
        $renewal_data['new_plan_date'] = isset($plan_row['date']) ? $plan_row['date'] : 0;
        write_renewal_json($from_id, $renewal_data);

        step('renewal_service_confirm_service');
        sendMessage($from_id, $texts['create_factor'], $confirm_service);
        $factor_key = isset($texts['renew_service_factor']) ? 'renew_service_factor' : 'service_factor';
        sendMessage($from_id, sprintf(
            $texts[$factor_key],
            $renewal_data['new_server'],
            $renewal_data['new_plan_limit'],
            $renewal_data['new_plan_date'],
            $renewal_data['config_base_name'],
            number_format($price)
        ));
    } elseif ($user['step'] == 'renewal_service_confirm_service' and $text == '☑️ ایجاد سرویس') {
        // Money path lives in one place; see renewal_confirm().
        // Duplicate-tap guard: atomic add, 120s TTL (covers the request cap),
        // released on every exit path. Without ext-apcu this degrades to no lock.
        $lock_key = 'renewal_lock_' . $from_id;
        $locked = apcu_safe_add($lock_key, 1, 120);
        if ($locked === false) {
            sendMessage($from_id, $texts['wait']);
            return;
        }
        try {
            renewal_confirm($from_id);
        } finally {
            if ($locked === true) {
                apcu_safe_del($lock_key);
            }
        }
    } elseif ($text == '❌  انصراف' and $user['step'] == 'renewal_service_confirm_service') {
        step('none');
        foreach (array(
            $from_id . '-location.txt',
            $from_id . '-protocol.txt',
            $from_id . '-plan.txt',
            $from_id . '-service-name.txt',
            'renewal-service-' . $from_id . '.json'
        ) as $file_name) {
            delete_temp_file($file_name);
        }
        db_exec($sql, "DELETE FROM `service_factors` WHERE `from_id` = ?", "s", array($from_id));
        Logger::info('renewal_cancelled', array('from_id' => $from_id));
        sendMessage($from_id, sprintf($texts['start'], $first_name), $start_key);
    }
}


function change_charge_account_button_visibility($chat_id)
{
    global $my_texts, $sql;
    $row = $sql->query("SELECT `show_charge_account_btn` FROM `settings` LIMIT 1")->fetch_assoc();
    $currenButtonStatus = $row['show_charge_account_btn'] ?? 'active';
    if ($currenButtonStatus === 'active') {
        $new_status = 'inactive';
        $successMsg = $my_texts['charge_button_disabled'];
    } else {
        $new_status = 'active';
        $successMsg = $my_texts['charge_button_enabled'];
    }
    $sql->query("UPDATE `settings` SET `show_charge_account_btn` = '$new_status' LIMIT 1");
    sendMessage($chat_id, $successMsg);
}

function get_charge_account_button_status()
{
    global $sql;
    $row = $sql->query("SELECT `show_charge_account_btn` FROM `settings` LIMIT 1")->fetch_assoc();
    $status = $row['show_charge_account_btn'] ?? 'active';
    return ($status === 'active') ? '💸 شارژ حساب' : '';
}

function change_account_status_changer_button_visibility($chat_id)
{
    global $my_texts, $sql;
    $row = $sql->query("SELECT `show_account_status_changer_btn` FROM `settings` LIMIT 1")->fetch_assoc();
    $currenButtonStatus = $row['show_account_status_changer_btn'] ?? 'active';
    if ($currenButtonStatus === 'active') {
        $new_status = 'inactive';
        $successMsg = $my_texts['account_status_changer_button_disabled'];
    } else {
        $new_status = 'active';
        $successMsg = $my_texts['account_status_changer_button_enabled'];
    }
    $sql->query("UPDATE `settings` SET `show_account_status_changer_btn` = '$new_status' LIMIT 1");
    sendMessage($chat_id, $successMsg);
}

function get_account_status_changer_button_status()
{
    global $sql, $texts;
    $row = $sql->query("SELECT `show_account_status_changer_btn` FROM `settings` LIMIT 1")->fetch_assoc();
    $status = $row['show_account_status_changer_btn'] ?? 'active';
    return ($status === 'active') ? $texts['account_status_changer_button'] : '';
}

function get_admin_ids()
{
    global $sql, $config;
    $key = [];
    $res = $sql->query("SELECT `chat_id` FROM `admins`");
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $key[] = $row['chat_id'];
        }
    }
    $key[] = $config['dev'];
    return $key;
}
