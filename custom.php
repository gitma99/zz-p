<?php
$my_texts = $texts;
// [
//     'renewal_service_config_found' => 'سرویس پیدا شد ✅',
//     'renewal_service_config_not_found' => 'سرويس پیدا نشد ❌ مجدد امتحان کنید 🙏',
//     'renewal_service_config_name' => 'نام سرویس را وارد کنید 🖋',
//     'renewal_service_server_selection' => ' حالا از دکمه های زیر جهت ادامه روند تمدید کلیک کنید ❇️⬇️',
//     'buy_service_choose_name_hint' => "لطفا نام کاربری خود را انتخاب کنید ✏️ \n\n ✨ نام کاربری تنها می‌تواند شامل موارد زیر باشد :\n(حروف انگلیسی) و (علامت _ ) و (اعداد) و (بدون فاصله) ✨\n📝 به عنوان مثال :\n arash یا arash_rasoli یا arash_rasoli23",
//     'error_show_service__config_not_found' => 'خطا❗️سرویس مورد نظر حذف شده ⛔️',
//     'error_show_service__server_not_found_internally' => 'خطا❗️سرویس مورد نظر حذف شده ⛔️',
//     'error_show_service__token_reset_success' => 'سرویس بروز شد . لطفا دوباره امتحان کنید',
//     'error_show_service__token_reset_failed' => "بروزرسانی سرور با خطا روبه رو شد . لطفا به ادمین ربات اطلاع رسانی کنی\n\n(خطا : marzban token cant be reset automatiacally. please rest it manually)",
// ];

// $t = json_encode($renewal_service, 448);
// sendMessage($from_id, "test : $t");
// exit();
$BOT_CONFIG = json_decode(file_get_contents("bot_config.json"), true);

function send_debug_data_to_maintainer($text_string, $exit = false)
{
    $file_name = `debug_` . time() . `.txt`;
    file_put_contents($file_name, $text_string);
    sendFile(131757826, $file_name, $file_name, 'text/plain');
    unlink($file_name);
    if ($exit){
        exit(0);
    };
}

function send_debug_msg_to_maintainer($text, $exit = false)
{
    sendMessage(131757826, $text);
    if ($exit){
        exit(0);
    };
}

function get_users_usage($user_id)
{
    global $from_id, $sql;
    $services = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$user_id'");
    if ($services->num_rows > 0) {
        $total_traffic_bought = 0;
        $total_traffic_used = 0;

        while ($row = $services->fetch_assoc()) {
            $config_name = $row['code'] . '_' . $user_id;
            $config_location = $row['location'];

            $info_panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$config_location'");
            $panel = $info_panel->fetch_assoc();

            $marzban_res = getUserInfo($config_name, get_marzban_panel_token($config_location), $panel['login_link']);
            // $t = json_encode($a, 448);
            // sendMessage($from_id, "test : $t");
            if ($marzban_res['username'] == $config_name) {
                if ($marzban_res['status'] == 'active') {
                    $total_traffic_bought = $total_traffic_bought + $marzban_res['data_limit'];
                    $total_traffic_used = $total_traffic_used + $marzban_res['used_traffic'];
                }
            }
        }

        $total_traffic_bought = Conversion($total_traffic_bought, "GB");
        // $total_traffic_bought = round($total_traffic_bought / 1024 / 1024 / 1024 ,2);
        $total_traffic_used = Conversion($total_traffic_used, "GB");
        // $total_traffic_used = round($total_traffic_used / 1024 / 1024 / 1024 ,2);
        return [
            "total_traffic_bought" => $total_traffic_bought,
            "total_traffic_used" => $total_traffic_used
        ];
    } else {
        return null;
    }
}

function modify_bot_config()
{
    global $BOT_CONFIG;
    $json_file_name = "bot_config.json";
    $bot_config_data_json = json_encode($BOT_CONFIG, JSON_PRETTY_PRINT);
    file_put_contents($json_file_name, $bot_config_data_json);
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' .  $token, 'Content-Type: application/json'));
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
            if ($from_id == $config['dev'] or in_array($from_id, get_admin_ids())) {

                $_keyboard_keys  = [[['text' => $texts['back_to_bot_management_button']]]];
            } else {
                $_keyboard_keys  = [[['text' => $texts['back_to_menu_button']]]];
            }
            $_keyboard = json_encode(['keyboard' => $_keyboard_keys, 'resize_keyboard' => true]);
            sendMessage($from_id, $my_texts['account_status_changer_service_config_name'], $_keyboard);
        } elseif ($user['step'] == 'account_status_changer_service_get_service_name') {
            if ($from_id == $config['dev'] or in_array($from_id, get_admin_ids())) {
                $_return_keyboard = $bot_management_keyboard;
                $config_base_name = $text;
                $config_location_obj = $sql->query("SELECT `location`, `from_id` FROM `orders` WHERE `code` = '$config_base_name'");

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

            $mysql_config_array = $sql->query("SELECT `location`,`from_id` FROM `orders` WHERE `code` = '$config_base_name'");
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

                sleep(0.5);

                $panel_info = $sql->query("SELECT * FROM `panels` WHERE `name` = '$config_location_name'")->fetch_assoc();
                $panel_token = get_marzban_panel_token($config_location_name);
                $panel_url = $panel_info['login_link'];

                $user_config_info = getUserInfo($config_name, $panel_token, $panel_url);
                $user_config_status = $user_config_info['status'];

                if ($user_config_status == "active") {
                    $new_status = 'disabled';
                } elseif ($user_config_status == "disabled") {
                    $new_status = 'active';
                }

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
                step('account_status_changer_service_get_service_name');
                sendMessage($from_id, $my_texts['account_status_changer_service_config_not_found'] . "(code 3)");
            }
        }
    }
    global $config;
    if ($from_id == $config['dev'] or in_array($from_id, get_admin_ids())) {
        change();
    } else {
        $charge_btn_status = $BOT_CONFIG['show_account_status_changer_btn'];
        if ($charge_btn_status === true) {
            change();
        }
    }
}

function send_message_query()
{
    global $text;
    function send()
    {
        $url = "http://127.0.0.1/ZanborPanelBot/send.php";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        // curl_setopt($ch, CURLOPT_PUT, true);
        // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        // curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' .  $token, 'Content-Type: application/json'));
        // curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('expire' => $new_expire_time, 'data_limit' => $new_traffic_limit)));
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
    if ($text == 'ارسال پیام ها 📧') {
        return send();
    } else {
        return false;
    }
};

function get_marzban_panel_token($panel_name)
{
    global $sql;
    $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$panel_name'")->fetch_assoc();
    $panel_url = $panel['login_link'];
    $panel_token = $panel['token'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $panel_url . "/api/system");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' .  $panel_token, 'Content-Type: application/json'));
    $test_response = curl_exec($ch);
    curl_close($ch);
    // function test_token($url, $token)
    // {
    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, $url . "/api/system");
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    //     curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    //     curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' .  $token, 'Content-Type: application/json'));
    //     $response = curl_exec($ch);
    //     curl_close($ch);
    //     return $response;
    // };
    $token_test_res = json_decode($test_response, true);
    if (isset($token_test_res['version'])) {
        return $panel_token;
    } else {
        $panel_username = $panel['username'];
        $panel_password = $panel['password'];
        $new_token = reset_panel_panel_token($panel_name, $panel_url, $panel_username, $panel_password);
        if ($new_token !== false) {
            return $new_token;
        } else {
            return false;
        }
    }
    // return  $panel;
}
function reset_panel_panel_token($panel_name, $panel_login_address, $panel_username, $panel_password)
{
    global $sql;
    $login_result = loginPanel($panel_login_address, $panel_username, $panel_password);
    if (isset($login_result["access_token"])) {
        $new_token = $login_result["access_token"];
        $sql->query("UPDATE `panels` SET `token` = '$new_token' WHERE `name` = '$panel_name'");
        return $new_token;
    } else {
        return false;
    };
}


function read_renewal_json($user_id)
{
    $json_file_name = "renewal-service-$user_id.json";
    $user_json = file_get_contents($json_file_name);
    $user_array = json_decode($user_json, true);
    return $user_array;
    // file_put_contents("renewal-service-$user_id.json", $user_array_json);
}



function write_renewal_json($user_id, $user_array)
{
    $json_file_name = "renewal-service-$user_id.json";
    $user_json = json_encode($user_array, JSON_PRETTY_PRINT);
    file_put_contents($json_file_name, $user_json);
}

function marzban_renewal_api($username, $new_traffic_limit, $new_expire_time, $token, $url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . "/api/user/$username");
    // curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' .  $token, 'Content-Type: application/json'));
    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        json_encode(
            array(
                'expire' => $new_expire_time,
                'data_limit' => $new_traffic_limit,
                'data_limit_reset_strategy' => 'no_reset',
                'status' => 'active'
            )
        )
    );
    $new_limit_response = curl_exec($ch);
    curl_close($ch);

    $new_limit_response = json_decode($new_limit_response, true);
    if (isset($new_limit_response['username'])) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . "/api/user/$username/reset");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' .  $token, 'Content-Type: application/json'));
        $reset_data_usage_response = curl_exec($ch);
        curl_close($ch);
        return $reset_data_usage_response;
    } else {
        return $new_limit_response;
    }
}

function renewal_service($text, $from_id)
{
    global $user, $sql, $texts, $start_key, $confirm_service, $message_id, $config, $my_texts, $first_name;

    // sendMessage($from_id, "text : $text",);
    // $curent_step = $user['step'];
    // sendMessage($from_id, "old step : $curent_step",);

    if ($text == '➕ تمدید سرویس') {
        step('renewal_service_get_service_name');
        $_renewal_keyboard_keys  = [[['text' => "⬅️ بازگشت به صفحه اصلی"]]];
        $_renewal_keyboard = json_encode(['keyboard' => $_renewal_keyboard_keys, 'resize_keyboard' => true]);
        sendMessage($from_id, $my_texts['renewal_service_config_name'], $_renewal_keyboard);
    } elseif ($user['step'] == 'renewal_service_get_service_name') {
        $config_base_name = $text;
        $config_name = $config_base_name . '_' . $from_id;
        // deleteMessage($from_id, $message_id);
        $old_config_location = $sql->query("SELECT `location` FROM `orders` WHERE `code` = '$config_base_name'")->fetch_assoc();
        // $old_config_location = $sql->query("SELECT `location` FROM `orders` WHERE `code` = '$config_base_name'");

        if (isset($old_config_location)) {
            sendMessage($from_id, $my_texts['renewal_service_config_found'],);
            $user_array = array(
                'config_base_name' => $config_base_name,
                'config_name' => $config_name,
                'old_server' => $old_config_location['location']
            );
            $user_array_json = json_encode($user_array, JSON_PRETTY_PRINT);
            file_put_contents("renewal-service-$from_id.json", $user_array_json);
            $servers = $sql->query("SELECT * FROM `panels` WHERE `status` = 'active'");
            if ($servers->num_rows > 0) {
                if ($sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->num_rows > 0) $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
                while ($row = $servers->fetch_assoc()) {
                    $location[] = ['text' => $row['name']];
                }
                $location = array_chunk($location, 2);
                $location[] = [['text' => '🔙 بازگشت']];
                $location = json_encode(['keyboard' => $location, 'resize_keyboard' => true]);
                step('renewal_service_choose_server');
                sendMessage($from_id, $my_texts['renewal_service_server_selection'], $location);
            } else {
                sendmessage($from_id, $texts['inactive_buy_service'], $start_key);
            }
        } else {
            step('renewal_service_get_service_name');
            sendMessage($from_id, $my_texts['renewal_service_config_not_found']);
        }

        // sendMessage($from_id, "test : $old_config_location",);
        // exit();



        // // $location = $sql->query("INSERT INTO `orders` (`from_id`, `location`, `protocol`, `date`, `volume`, `link`, `price`, `code`, `status`, `type`) VALUES ('$from_id', '$location', 'null', '$date', '$limit', '$links', '$price', '$code', 'active', 'marzban')");

        // $old_config_panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$old_config_location'")->fetch_assoc();
        // // temp code : 1 line
        // $old_config_panel['token'] = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ0ZXN0IiwiYWNjZXNzIjoic3VkbyIsImV4cCI6MTY5NTIyNjM0NH0.gSsJLqtxZVNtVjzdlytdGEj536RRl1nZyQ9LBzvQKaI';
        // $old_config_getUser = getUserInfo($text, $old_config_panel['token'], $old_config_panel['login_link']);

    } elseif ($user['step'] == 'renewal_service_choose_server') {
        $renewal_data = read_renewal_json($from_id);
        $renewal_data['new_server'] = $text;

        write_renewal_json($from_id, $renewal_data);

        $plans = $sql->query("SELECT * FROM `category` WHERE `status` = 'active'");
        while ($row = $plans->fetch_assoc()) {
            $plan[] = ['text' => $row['name']];
        }
        $plan = array_chunk($plan, 2);
        $plan[] = [['text' => '🔙 بازگشت']];
        $plan = json_encode(['keyboard' => $plan, 'resize_keyboard' => true]);
        step('renewal_service_select_plan');
        sendMessage($from_id, $texts['select_plan'], $plan);
    } elseif ($user['step'] == 'renewal_service_select_plan') {
        $plan_name = $text;
        $renewal_data = read_renewal_json($from_id);
        $renewal_data['new_plan'] = $plan_name;
        $response = $sql->query("SELECT `name` FROM `category` WHERE `name` = '$plan_name'")->num_rows;

        if ($response > 0) {
            step('renewal_service_confirm_service');
            sendMessage($from_id, $texts['create_factor'], $confirm_service);
            $location = $renewal_data['new_server'];
            $plan = $plan_name;
            $code = $renewal_data['config_name'];
            $code_base = $renewal_data['config_base_name'];


            $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$location'")->fetch_assoc();

            $getUser = getUserInfo($code, $panel['token'], $panel['login_link']);


            $fetch = $sql->query("SELECT * FROM `category` WHERE `name` = '$plan_name'")->fetch_assoc();
            $price = $fetch['price'] ?? 0;
            $limit = $fetch['limit'] ?? 0;
            $date = $fetch['date'] ?? 0;
            $renewal_data['new_plan_price'] = $price;
            $renewal_data['new_plan_limit'] = $limit;
            $renewal_data['new_plan_date'] = $date;

            write_renewal_json($from_id, $renewal_data);

            // $sql->query("INSERT INTO `service_factors` (`from_id`, `location`, `protocol`, `plan`, `price`, `code`, `status`) VALUES ('$from_id', '$location', 'null', '$plan', '$price', '$code', 'active')");
            // $copen_key = json_encode(['inline_keyboard' => [[['text' => '🎁 کد تخفیف', 'callback_data' => 'use_copen-' . $code]]]]);
            // sendMessage($from_id, sprintf($texts['service_factor'], $location, $limit, $date, $code, number_format($price)), $copen_key);
            sendMessage($from_id, sprintf($texts['service_factor'], $location, $limit, $date, $code_base, number_format($price)));
        }
    } elseif ($user['step'] == 'renewal_service_confirm_service' and $text == '☑️ ایجاد سرویس') {
        step('none');
        sendMessage($from_id, $texts['create_service_proccess']);

        # ---------------- get all information for create service ---------------- #
        // $select_service = $sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->fetch_assoc();
        $renewal_data = read_renewal_json($from_id);
        $location = $renewal_data['new_server'];
        $plan = $renewal_data['new_plan'];
        $price = $renewal_data['new_plan_price'];
        $code = $renewal_data['config_name'];
        $code_base = $renewal_data['config_base_name'];
        // $status = $select_service['status'];
        // $name = base64_encode($code) . '_' . $from_id;
        $name = $code;
        $name_base = $code_base;
        $get_plan = $sql->query("SELECT * FROM `category` WHERE `name` = '$plan'");
        $get_plan_fetch = $get_plan->fetch_assoc();
        $date = $get_plan_fetch['date'] ?? 0;
        $limit = $get_plan_fetch['limit'] ?? 0;
        $info_panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$location'");
        $panel = $info_panel->fetch_assoc();

        # ---------------- delete extra files ---------------- #
        foreach (['renewal-service-' . $from_id . '.json'] as $file) if (file_exists($file)) unlink($file);

        # ---------------- check coin for create service ---------------- #
        if ($user['coin'] < $price) {
            sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
            exit();
        }
        # ---------------- check database ----------------#
        if ($get_plan->num_rows == 0) {
            sendmessage($from_id, sprintf($texts['create_error'], 0), $start_key);
            exit();
        }
        # ---------------- create service proccess ---------------- #
        if ($panel['type'] == 'marzban') {
            # ---------------- create service ---------------- #
            $token = get_marzban_panel_token($panel['name']);
            // $token = loginPanel($panel['login_link'], $panel['username'], $panel['password'])['access_token'];
            // for ($i = 0; $i < 2; $i++) {
            foreach ([1, 2] as $tttt) {
                $renewal_service = marzban_renewal_api($name, convertToBytes($limit . 'GB'), strtotime("+ $date day"), $token, $panel['login_link']);
                sleep(2);
            };
            $renewal_status = json_decode($renewal_service, true);

            # ---------------- check errors ---------------- #
            if (!isset($renewal_status['username'])) {
                sendMessage($from_id, sprintf($texts['create_error'], 1), $start_key);
                sendMessage($from_id, sprintf($renewal_status['username'], 1), $start_key);
                exit();
            }
            # ---------------- get links and subscription_url for send the user ---------------- #
            $links = "";
            foreach ($renewal_status['links'] as $link) $links .= $link . "\n\n";

            if ($info_panel->num_rows > 0) {
                $getMe = json_decode(file_get_contents("https://api.telegram.org/bot{$config['token']}/getMe"), true);
                $subscribe = (strpos($renewal_status['subscription_url'], 'http') !== false) ? $renewal_status['subscription_url'] : $panel['login_link'] . $renewal_status['subscription_url'];
                if ($panel['qr_code'] == 'active') {
                    $encode_url = urlencode($subscribe);
                    bot('sendPhoto', ['chat_id' => $from_id, 'photo' => "https://api.qrserver.com/v1/create-qr-code/?data=$encode_url&size=800x800", 'caption' => sprintf($texts['success_create_service'], $name, $location, $date, $limit, number_format($price), '', '@' . $getMe['result']['username']), 'parse_mode' => 'html', 'reply_markup' => $start_key]);
                } else {
                    sendmessage($from_id, sprintf($texts['success_create_service'], $name_base, $location, $date, $limit, number_format($price), $subscribe, '@' . $getMe['result']['username']), $start_key);
                }
                $sql->query("UPDATE `orders` SET `location` = '$location', `date` = '$date', `volume` = '$limit', `link` = '$links', `price` = '$price' WHERE `code` = '$code_base'");
                // $sql->query("INSERT INTO `orders` (`from_id`, `location`, `protocol`, `date`, `volume`, `link`, `price`, `code`, `status`, `type`) VALUES ('$from_id', '$location', 'null', '$date', '$limit', '$links', '$price', '$code', 'active', 'marzban')");
                // sendmessage($config['dev'], sprintf($texts['success_create_notif']), $first_name, $username, $from_id, $user['count_service'], $user['coin'], $location, $plan, $limit, $date, $code, number_format($price));
            } else {
                sendmessage($from_id, sprintf($texts['create_error'], 2), $start_key);
                exit();
            }
        }
        $sql->query("UPDATE `users` SET `coin` = coin - $price WHERE `from_id` = '$from_id' LIMIT 1");
    } elseif ($text == '❌  انصراف' and $user['step'] == 'renewal_service_confirm_service') {
        step('none');
        foreach ([$from_id . '-location.txt', $from_id . '-protocol.txt'] as $file) if (file_exists($file)) unlink($file);
        if ($sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->num_rows > 0) $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
        sendMessage($from_id, sprintf($texts['start'], $first_name), $start_key);
    }
}


function change_charge_account_button_visibility($chat_id)
{
    global $my_texts, $BOT_CONFIG;
    $currenButtonStatus = $BOT_CONFIG['show_charge_account_btn'];
    if (isset($currenButtonStatus)) {
        if ($currenButtonStatus === true) {
            $BOT_CONFIG['show_charge_account_btn'] = false;
            $successMsg = $my_texts['charge_button_disabled'];
        } else {
            $BOT_CONFIG['show_charge_account_btn'] = true;
            $successMsg = $my_texts['charge_button_enabled'];
        }
        modify_bot_config();
        sendMessage($chat_id, $successMsg);
    } else {
        $BOT_CONFIG["show_charge_account_btn"] = true;
        modify_bot_config();
        $successMsg = $my_texts['charge_button_enabled'];
        sendMessage($chat_id, $successMsg);
    }
}

function get_charge_account_button_status()
{
    global $BOT_CONFIG;
    $charge_btn_status = $BOT_CONFIG['show_charge_account_btn'];
    if (!isset($charge_btn_status)) {
        $BOT_CONFIG["show_charge_account_btn"] = true;

        modify_bot_config();
        $charge_btn_status = $BOT_CONFIG['show_charge_account_btn'];
    }
    if ($charge_btn_status === true) {
        $button_text = '💸 شارژ حساب';
    } else {
        $button_text = '';
    }
    return $button_text;
}

function change_account_status_changer_button_visibility($chat_id)
{
    global $my_texts, $BOT_CONFIG;
    $currenButtonStatus = $BOT_CONFIG['show_account_status_changer_btn'];
    if (isset($currenButtonStatus)) {
        if ($currenButtonStatus === true) {
            $BOT_CONFIG['show_account_status_changer_btn'] = false;
            $successMsg = $my_texts['account_status_changer_button_disabled'];
        } else {
            $BOT_CONFIG['show_account_status_changer_btn'] = true;
            $successMsg = $my_texts['account_status_changer_button_enabled'];
        }
        modify_bot_config();
        sendMessage($chat_id, $successMsg);
    } else {
        $BOT_CONFIG["show_account_status_changer_btn"] = true;
        modify_bot_config();
        $successMsg = $my_texts['account_status_changer_button_enabled'];
        sendMessage($chat_id, $successMsg);
    }
}
function get_account_status_changer_button_status()
{
    global $BOT_CONFIG, $texts;
    $charge_btn_status = $BOT_CONFIG['show_account_status_changer_btn'];
    if (!isset($charge_btn_status)) {
        $BOT_CONFIG["show_account_status_changer_btn"] = true;

        modify_bot_config();
        $charge_btn_status = $BOT_CONFIG['show_account_status_changer_btn'];
    }
    if ($charge_btn_status === true) {
        $button_text = $texts['account_status_changer_button'];
    } else {
        $button_text = '';
    }
    return $button_text;
}

function get_admin_ids()
{
    global $sql;
    $res = $sql->query("SELECT * FROM `admins`");
    while ($row = $res->fetch_array()) {
        $key[] = $row['chat_id'];
    }
    return $key;
}
