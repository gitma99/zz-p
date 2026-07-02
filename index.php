<?php
// send_debug_msg_to_maintainer($text, $from_id, true)
require_once __DIR__ . "/App/vars.php";

ini_set('display_errors', 0); // Disable error display on the screen
ini_set('log_errors', 1); // Enable error logging to a file
if (!is_dir($LOG_DIR_PATH)) {
    mkdir($LOG_DIR_PATH, 0777, true); // Create directory with recursive permissions
}
ini_set('error_log', $LOG_DIR_PATH . '/error.log'); // Specify the path to the error log file
error_reporting(E_ALL); // Set the error reporting level as needed
date_default_timezone_set("UTC");

try {
    require_once __DIR__ . '/App/Utils/request_cache.php';
    require_once __DIR__ . "/App/Debug/debug.php";
    require_once __DIR__ . "/App/apis/telegram-api/api/delete_message.php";
    require_once __DIR__ . "/App/apis/telegram-api/api/edit_message.php";
    require_once __DIR__ . "/App/apis/telegram-api/api/forward_message.php";
    require_once __DIR__ . "/App/apis/telegram-api/api/send_alert.php";
    require_once __DIR__ . "/App/apis/telegram-api/api/send_file.php";
    require_once __DIR__ . "/App/apis/telegram-api/api/send_message.php";
    require_once __DIR__ . "/App/apis/telegram-api/functions/responses.php";
    require_once __DIR__ . "/App/Utils/temp_tools.php";


    require_once 'config.php';
    

    // Allow longer web requests for operations that may take >30s.
    // Uses optional config value `web_request_timeout` (seconds). Defaults to 120s.
    if (php_sapi_name() !== 'cli') {
        $web_timeout = 120;
        if (isset($config) && isset($config['web_request_timeout']) && is_numeric($config['web_request_timeout'])) {
            $web_timeout = intval($config['web_request_timeout']);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit($web_timeout);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    if (!isset($from_id)) {
        readfile("Templates/under_maintenance.html");
        exit(0);
    }

    require_once __DIR__ . '/App/apis/marzban-api/api.php';
    require_once __DIR__ . '/custom.php';
    require_once __DIR__ . "/App/index_maps.php";
    // require_once __DIR__ . '/api/sanayi.php';
    // require_once  __DIR__ . '/api/hiddify.php';

    if ($user['status'] == 'inactive' and $from_id != $config['dev']) {
        sendMessage($from_id, $texts['block']);
        exit(0);
    }

    if ($data == 'join') {
        if (isJoin($from_id)) {
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, $texts['success_joined'], $start_key);
        } else {
            alert($texts['not_join']);
        }
        exit(0);
    }

    try {
        $is_user_joined_to_channel = isJoin($from_id);
    } catch (\Throwable $e) {
        // $error_msg = json_decode($e->getMessage())["response"]["description"] ?? '';
        if (strpos($e->getMessage(), 'member list is inaccessible') !== false) {
            if (in_array($from_id, $admin_id_array)){
                sendMessage($from_id, "❌ ربات دسترسی به لیست اعضای کانال مربوطه ندارد!");
            } else {
                foreach ($admin_id_array as $admin_id) {
                    sendMessage($admin_id, "❌ ربات دسترسی به لیست اعضای کانال مربوطه ندارد!");
                }
                sendMessage($from_id, "❌ خطایی رخ داد. لطفا با مدیر تماس بگیرید.", $back_panel);
            }
        } else {
            throw $e;
        }
    }

    if ($is_user_joined_to_channel == false && !in_array($from_id, $admin_id_array)) {
        joinSend($from_id);
        exit(0);
    }

    # ------------ main ------------ #
    switch ($data) {
        case 'no_service_list_available':
            alert($texts["show_service_list__error__no_service_list_available"]);
            break;
        case 'null':
            alert(null);
            break;
        case 'none':
            alert(null);
            break;
    }

    if ($text == $texts['back_to_menu_button']) {
        step('none');
        sendMessage($from_id, $texts['back_to_menu'], $start_key);
        exit(0);
    } elseif ($text == $texts['back_to_bot_management_button']) {
        step('panel');
        sendMessage($from_id, "👮‍♂️ - سلام ادمین [ <b>$first_name</b> ] عزیز !\n\n⚡️به پنل مدیریت ربات خوش آمدید.\n🗃 ورژن فعلی ربات : <code>{$config['version']}</code>\n\n⚙️ جهت مدیریت ربات ، یکی از گزینه های زیر را انتخاب کنید.", $bot_management_keyboard);
        exit(0);
    } else {
        renewal_service($text, $from_id);
        change_account_status($text, $from_id);
    }
    
    if ($text == '/start' or $text == '🔙 بازگشت' or $text == '/back') {
        step('none');
        sendMessage($from_id, sprintf($texts['greetings'] . $texts['start'], $first_name), $start_key);
    } elseif ($data == 'back_to_home') {
        step('none');
        editMessage($from_id, sprintf($texts['greetings'] . $texts['start'], $first_name), $message_id, $start_key);
    } elseif ($text == '❌  انصراف' and $user['step'] == 'confirm_service') {
        step('none');
        delete_temp_file($from_id . '-location.txt');
        delete_temp_file($from_id . '-protocol.txt');
        if ($sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->num_rows > 0)
            $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
        sendMessage($from_id, sprintf($texts['greetings'] . $texts['start'], $first_name), $start_key);
    } elseif ($text == '🛒 خرید سرویس') {
        $servers = $sql->query("SELECT * FROM `panels` WHERE `status` = 'active'");
        if ($servers->num_rows > 0) {
            step('buy_service');
            if ($sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->num_rows > 0)
                $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
            while ($row = $servers->fetch_assoc()) {
                $location[] = ['text' => $row['name']];
            }
            $location = array_chunk($location, 2);
            $location[] = [['text' => '🔙 بازگشت']];
            $location = json_encode(['keyboard' => $location, 'resize_keyboard' => true]);
            sendMessage($from_id, $texts['select_location'], $location);
        } else {
            sendmessage($from_id, $texts['inactive_buy_service'], $start_key);
        }
    } elseif ($user['step'] == 'buy_service') {
        $response = $sql->query("SELECT `name` FROM `panels` WHERE `name` = '$text'");
        if ($response->num_rows == 0) {
            sendMessage($from_id, $texts['choice_error']);
            exit(1);
        } else {
            step('select_plan');
            $plans = $sql->query("SELECT * FROM `category` WHERE `status` = 'active'");
            while ($row = $plans->fetch_assoc()) {
                $plan[] = ['text' => $row['name']];
            }
            $plan = array_chunk($plan, 2);
            $plan[] = [['text' => '🔙 بازگشت']];
            $plan = json_encode(['keyboard' => $plan, 'resize_keyboard' => true]);
            write_temp_file($text, "$from_id-location.txt");
            sendMessage($from_id, $texts['select_plan'], $plan);
        }
    } elseif ($user['step'] == 'select_plan') {
        $response = $sql->query("SELECT `name`, `price` FROM `category` WHERE `name` = '$text'");
        if ($response->num_rows == 0) {
            sendMessage($from_id, $texts['choice_error']);
            exit(1);
        } else {
            $price = $response->fetch_assoc()['price'] ?? 0;
            if ($user['coin'] < $price) {
                step('none');
                sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
                exit(0);
            }
            step('choose_name');
            $_keyboard_btns = [[['text' => '🔙 بازگشت']]];
            $_keyboard = json_encode(['keyboard' => $_keyboard_btns, 'resize_keyboard' => true]);
            write_temp_file($text, "$from_id-plan.txt");
            sendMessage($from_id, $my_texts['buy_service_choose_name_hint'], $_keyboard);
        }
    } elseif ($user['step'] == 'choose_name') {
        $selected_name = $text;
        // $selected_name_full = $code_base . '_' . $from_id;



        $plan_name = read_temp_file("$from_id-plan.txt")[0];
        $response = $sql->query("SELECT `name` FROM `category` WHERE `name` = '$plan_name'")->num_rows;
        if ($response == 0) {
            sendMessage($from_id, $texts['choice_error']);
        }

        $location = read_temp_file("$from_id-location.txt")[0];
        $plan = $plan_name;
        $code_base = $selected_name;
        $code = $code_base . '_' . $from_id;

        if (strlen($code_base) > 20) {
            sendMessage($from_id, $texts['buy_service__choose_name__error__too_long']);
            sendMessage($from_id, $my_texts['buy_service_choose_name_hint'], json_encode(['keyboard' => [[['text' => '🔙 بازگشت']]], 'resize_keyboard' => true]));
            exit(0);
        };

        $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$location'")->fetch_assoc();
        $getUser = getUserInfo($code, get_marzban_panel_token($location), $panel['login_link']);
        # if ($getUser['detail'] == 'Could not validate credentials') {
        // if (in_array($getUser['detail'], ['Could not validate credentials', 'Not authenticated'])) {
        if ($getUser[0] == false) {
            $new_marzban_token = get_marzban_panel_token($panel['name']);

            if ($new_marzban_token !== false) {
                $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$location'")->fetch_assoc();
                $getUser = getUserInfo($code, get_marzban_panel_token($location), $panel['login_link']);
            } else {
                $plan = [];
                $plan[] = [['text' => '🔙 بازگشت']];
                $plan = json_encode(['keyboard' => $plan, 'resize_keyboard' => true]);
                sendMessage($from_id, "{$texts['server_connection_failed']}({$panel['name']} token cant be renewed!!)", $plan);
                exit(0);
            }
        }

        if (isset($getUser[2])) {
            $repeated_db_order_code_count = $sql->query("SELECT `code` FROM `orders` WHERE `code` = '{$code_base}' AND `from_id` = '$from_id'")->num_rows;
            if ($getUser[1] == true || $repeated_db_order_code_count != 0) {
                // if ((!isset($getUser['links']) and $getUser == false)) {
                $plan = [];
                $plan[] = [['text' => '🔙 بازگشت']];
                $plan = json_encode(['keyboard' => $plan, 'resize_keyboard' => true]);

                // sendMessage($from_id, $custo['renew_service_server_selection'], $plan);
                sendMessage($from_id, $my_texts['repeated_config_name'], $plan);
                sendMessage($from_id, $my_texts['buy_service_choose_name_hint'], $plan);
                step('choose_name');
                exit();
            } elseif ($getUser[1] == false) {
                sendMessage($from_id, $texts['create_factor'], $confirm_service);

                $fetch = $sql->query("SELECT * FROM `category` WHERE `name` = '$plan_name'")->fetch_assoc();
                $price = $fetch['price'] ?? 0;
                $limit = $fetch['limit'] ?? 0;
                $date = $fetch['date'] ?? 0;

                $sql->query("INSERT INTO `service_factors` (`from_id`, `location`, `protocol`, `plan`, `price`, `code`, `status`) VALUES ('$from_id', '$location', 'null', '$plan', '$price', '$code_base', 'active')");
                $copen_key = json_encode(['inline_keyboard' => [[['text' => '🎁 کد تخفیف', 'callback_data' => 'use_copen-' . $code]]]]);
                // sendMessage($from_id, sprintf($texts['service_factor'], $location, $limit, $date, $code_base, number_format($price)), $copen_key);
                sendMessage($from_id, sprintf($texts['service_factor'], $location, $limit, $date, $code_base, number_format($price)));
                step('confirm_service');
                exit();
            } else {
                $_keys = [[['text' => '🔙 بازگشت']]];
                $_keyboard = json_encode(['keyboard' => $_keys, 'resize_keyboard' => true]);
                // sendMessage($from_id, $custo['renew_service_server_selection'], $plan);
                sendMessage($from_id, "{$texts['config_name_verification_failed']}({$getUser[2]['detail']})", $_keyboard);
                exit();
            }
        } else {
            $plan = [];
            $plan[] = [['text' => '🔙 بازگشت']];
            $plan = json_encode(['keyboard' => $plan, 'resize_keyboard' => true]);
            // sendMessage($from_id, $custo['renew_service_server_selection'], $plan);
            sendMessage($from_id, $texts['invalid_config_name'], $plan);
            sendMessage($from_id, $my_texts['buy_service_choose_name_hint'], $plan);
            step('choose_name');
            exit();
        }
    } elseif ($data == 'cancel_copen') {
        step('confirm_service');
        deleteMessage($from_id, $message_id);
    } elseif (strpos($data, 'use_copen') !== false and $user['step'] == 'confirm_service') {
        $code = explode('-', $data)[1];
        step('send_copen-' . $code);
        sendMessage($from_id, $texts['send_copen'], $cancel_copen);
    } elseif (strpos($user['step'], 'send_copen-') !== false) {
        $code = explode('-', $user['step'])[1];
        $copen = $sql->query("SELECT * FROM `copens` WHERE `copen` = '$text'");
        $service = $sql->query("SELECT * FROM `service_factors` WHERE `code` = '$code'")->fetch_assoc();
        if ($copen->num_rows > 0) {
            $copen = $copen->fetch_assoc();
            if ($copen['status'] == 'active') {
                if ($copen['count_use'] > 0) {
                    step('confirm_service');
                    $price = $service['price'] * (intval($copen['percent']) / 100);
                    $sql->query("UPDATE `service_factors` SET `price` = price - $price WHERE `code` = '$code'");
                    sendMessage($from_id, sprintf($texts['success_copen'], $copen['percent']), $confirm_service);
                } else {
                    sendMessage($from_id, $texts['copen_full'], $cancel_copen);
                }
            } else {
                sendMessage($from_id, $texts['copen_error'], $cancel_copen);
            }
        } else {
            sendMessage($from_id, $texts['copen_error'], $cancel_copen);
        }
    } elseif ($user['step'] == 'confirm_service' and $text == '☑️ ایجاد سرویس') {
        step('none');
        sendMessage($from_id, $texts['create_service_proccess']);
        # ---------------- delete extra files ---------------- #
        foreach (["{$from_id}-location.txt", "{$from_id}-protocol.txt", "{$from_id}-plan.txt", "{$from_id}-service-name.txt"] as $file_name)
            delete_temp_file($file_name);
        # ---------------- get all information for create service ---------------- #
        $select_service = $sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->fetch_assoc();
        $location = $select_service['location'];
        $plan = $select_service['plan'];
        $price = $select_service['price'];
        $code_base = $select_service['code'];
        $code = $code_base . '_' . $from_id;
        $status = $select_service['status'];
        // $name = base64_encode($code) . '_' . $from_id;
        $name = $code;
        $get_plan = $sql->query("SELECT * FROM `category` WHERE `name` = '$plan'");
        $get_plan_fetch = $get_plan->fetch_assoc();
        $date = $get_plan_fetch['date'] ?? 0;
        $limit = $get_plan_fetch['limit'] ?? 0;
        $info_panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$location'");
        $panel = $info_panel->fetch_assoc();
        # ---------------- check coin for create service ---------------- #
        if ($user['coin'] < $select_service['price']) {
            sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
            exit();
        }
        # ---------------- check database ----------------#
        if ($get_plan->num_rows == 0) {
            sendmessage($from_id, sprintf($texts['create_error'], 0), $start_key);
            throw new Exception("Plan check failed: plan '$plan' not found in database.");
        }
        # ---------------- create service proccess ---------------- #
        if ($panel['type'] == 'marzban') {
            # ---------------- set proxies and inbounds proccess for marzban panel ---------------- #
            $protocols = explode('|', $panel['protocols']);
            unset($protocols[count($protocols) - 1]);
            if ($protocols[0] == '')
                unset($protocols[0]);
            $proxies = array();
            foreach ($protocols as $protocol) {
                if ($protocol == 'vless' and $panel['flow'] == 'flowon') {
                    $proxies[$protocol] = array('flow' => 'xtls-rprx-vision');
                } else {
                    $proxies[$protocol] = new stdClass();
                }
            }
            // sendMessage($from_id, json_encode($protocols, 448));
            // sendMessage($from_id, json_encode($proxies, 448));
            $panel_inbounds = $sql->query("SELECT * FROM `marzban_inbounds` WHERE `panel` = '{$panel['code']}'");
            $inbounds = array();
            foreach ($protocols as $protocol) {
                while ($row = $panel_inbounds->fetch_assoc()) {
                    $inbounds[$protocol][] = $row['inbound'];
                }
            }
            // sendMessage($from_id, json_encode($inbounds, 448));
            # ---------------- create service ---------------- #
            $token = loginPanel($panel['login_link'], $panel['username'], $panel['password'])['access_token'];
            if ($token == false) {
                step('none');
                sendmessage($from_id, $my_texts['error_show_service__token_is_incorrect'], $start_key);
                exit();
            }
            $create_service = createService($name, convertToBytes($limit . 'GB'), strtotime("+ $date day"), $proxies, ($panel_inbounds->num_rows > 0) ? $inbounds : 'all', $token, $panel['login_link']);
            # ---------------- check errors ---------------- #
            if ($create_service[1] !== False) {
                sendMessage($from_id, sprintf($texts['create_error'], 1), $start_key);
                throw new Exception(json_encode($create_service), 1);
            }
            $create_status = $create_service[0];
            # ---------------- get links and subscription_url for send the user ---------------- #
            $links = "";
            foreach ($create_status['links'] as $link)
                $links .= $link . "\n\n";


            if ($info_panel->num_rows > 0) {
                $getMe = json_decode(file_get_contents("https://api.telegram.org/bot{$config['token']}/getMe"), true);
                $subscribe = (strpos($create_status['subscription_url'], 'http') !== false) ? $create_status['subscription_url'] : $panel['login_link'] . $create_status['subscription_url'];
                $caption_text = sprintf($texts['success_create_service'], $code_base, $location, $date, $limit, number_format($price), '', '@' . $getMe['result']['username']);
                $message_text = sprintf($texts['success_create_service'], $code_base, $location, $date, $limit, number_format($price), $subscribe, '@' . $getMe['result']['username']);
                sendSuccessResponse($from_id, $panel['qr_code'], $subscribe, $caption_text, $message_text, $start_key);
                $sql->query("INSERT INTO `orders` (`from_id`, `location`, `protocol`, `date`, `volume`, `link`, `price`, `code`, `status`, `type`) VALUES ('$from_id', '$location', 'null', '$date', '$limit', '$links', '$price', '$code_base', 'active', 'marzban')");
                // sendmessage($config['dev'], sprintf($texts['success_create_notif']), $first_name, $username, $from_id, $user['count_service'], $user['coin'], $location, $plan, $limit, $date, $code, number_format($price));
            } else {
                sendmessage($from_id, sprintf($texts['create_error'], 2), $start_key);
                throw new Exception("Panel exists check failed on success check: panel '$location' not found in database.");
            }
        } elseif ($panel['type'] == 'sanayi') {

            include_once 'api/sanayi.php';
            $xui = new Sanayi($panel['login_link'], $panel['token']);
            $san_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel['code']}'")->fetch_assoc();
            $create_service = $xui->addClient($name, $san_setting['inbound_id'], $date, $limit);
            $create_status = json_decode($create_service, true);
            # ---------------- check errors ---------------- #
            if ($create_status['status'] == false) {
                sendMessage($from_id, sprintf($texts['create_error'], 1), $start_key);
                throw new Exception("Sanayi create client API error: " . json_encode($create_status));
            }
            # ---------------- get links and subscription_url for send the user ---------------- #
            if ($info_panel->num_rows > 0) {
                $getMe = json_decode(file_get_contents("https://api.telegram.org/bot{$config['token']}/getMe"), true);
                $link = str_replace(['%s1', '%s2', '%s3'], [$create_status['results']['id'], str_replace(parse_url($panel['login_link'])['port'], json_decode($xui->getPortById($san_setting['inbound_id']), true)['port'], str_replace(['https://', 'http://'], ['', ''], $panel['login_link'])), $create_status['results']['remark']], $san_setting['example_link']);
                $caption_text = sprintf($texts['success_create_service_sanayi'], $name, $location, $date, $limit, number_format($price), $link, $create_status['results']['subscribe'], '@' . $getMe['result']['username']);
                sendSuccessResponse($from_id, $panel['qr_code'], $link, $caption_text, $caption_text, $start_key);
                $sql->query("INSERT INTO `orders` (`from_id`, `location`, `protocol`, `date`, `volume`, `link`, `price`, `code`, `status`, `type`) VALUES ('$from_id', '$location', 'null', '$date', '$limit', '$link', '$price', '$code', 'active', 'sanayi')");
                // sendMessage($config['dev'], sprintf($texts['success_create_notif']), $first_name, $username, $from_id, $user['count_service'], $user['coin'], $location, $plan, $limit, $date, $code, number_format($price));
            } else {
                sendMessage($from_id, sprintf($texts['create_error'], 2), $start_key);
                throw new Exception("Panel exists check failed on success check: panel '$location' not found in database.");
            }
        }
        $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
        $sql->query("UPDATE `users` SET `coin` = coin - $price, `count_service` = count_service + 1 WHERE `from_id` = '$from_id' LIMIT 1");
    } elseif ($text == '🎁 سرویس تستی (رایگان)' and $test_account_setting['status'] == 'active') {
        step('none');
        if ($user['test_account'] == 'no') {
            sendMessage($from_id, '⏳', $start_key);

            $panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '{$test_account_setting['panel']}'");
            $panel_fetch = $panel->fetch_assoc();

            try {
                if ($panel_fetch['type'] == 'marzban') {
                    # ---------------- set proxies and inbounds proccess for marzban panel ---------------- #
                    $protocols = explode('|', $panel_fetch['protocols']);
                    unset($protocols[count($protocols) - 1]);
                    if ($protocols[0] == '')
                        unset($protocols[0]);
                    $proxies = array();
                    foreach ($protocols as $protocol) {
                        if ($protocol == 'vless' and $panel_fetch['flow'] == 'flowon') {
                            $proxies[$protocol] = array('flow' => 'xtls-rprx-vision');
                        } else {
                            $proxies[$protocol] = new stdClass();
                        }
                    }

                    $panel_inbounds = $sql->query("SELECT * FROM `marzban_inbounds` WHERE `panel` = '{$panel_fetch['code']}'");
                    $inbounds = array();
                    foreach ($protocols as $protocol) {
                        while ($row = $panel_inbounds->fetch_assoc()) {
                            $inbounds[$protocol][] = $row['inbound'];
                        }
                    }
                    # ---------------------------------------------- #
                    $code = rand(111111, 999999);
                    $name = base64_encode($code) . '_' . $from_id;
                    $create_service = createService($name, convertToBytes($test_account_setting['volume'] . 'GB'), strtotime("+ {$test_account_setting['time']} hour"), $proxies, ($panel_inbounds->num_rows > 0) ? $inbounds : 'all', $panel_fetch['token'], $panel_fetch['login_link']);
                    $create_status = json_decode($create_service, true);
                    if (isset($create_status['username'])) {
                        $links = "";
                        foreach ($create_status['links'] as $link)
                            $links .= $link . "\n\n";
                        $subscribe = (strpos($create_status['subscription_url'], 'http') !== false) ? $create_status['subscription_url'] : $panel_fetch['login_link'] . $create_status['subscription_url'];
                        $sql->query("UPDATE `users` SET `count_service` = count_service + 1, `test_account` = 'yes' WHERE `from_id` = '$from_id'");
                        $sql->query("INSERT INTO `test_account` (`from_id`, `location`, `date`, `volume`, `link`, `price`, `code`, `status`) VALUES ('$from_id', '{$panel_fetch['name']}', '{$test_account_setting['date']}', '{$test_account_setting['volume']}', '$links', '0', '$code', 'active')");
                        deleteMessage($from_id, $message_id + 1);
                        sendMessage($from_id, sprintf($texts['create_test_account'], $test_account_setting['time'], $subscribe, $panel_fetch['name'], $test_account_setting['time'], $test_account_setting['volume'], base64_encode($code)), $start_key);
                    } else {
                        deleteMessage($from_id, $message_id + 1);
                        sendMessage($from_id, sprintf($texts['create_error'], 1), $start_key);
                        throw new Exception("Marzban test account creation failed: " . json_encode($create_status));
                    }
                }

                if ($panel_fetch['type'] == 'sanayi') {
                    include_once 'api/sanayi.php';
                    $code = rand(111111, 999999);
                    $name = base64_encode($code) . '_' . $from_id;
                    $xui = new Sanayi($panel_fetch['login_link'], $panel_fetch['token']);
                    $san_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel_fetch['code']}'")->fetch_assoc();
                    $create_service = $xui->addClient($name, $san_setting['inbound_id'], $test_account_setting['volume'], ($test_account_setting['time'] / 24));
                    $create_status = json_decode($create_service, true);
                    $link = str_replace(['%s1', '%s2', '%s3'], [$create_status['results']['id'], str_replace(parse_url($panel_fetch['login_link'])['port'], json_decode($xui->getPortById($san_setting['inbound_id']), true)['port'], str_replace(['https://', 'http://'], ['', ''], $panel_fetch['login_link'])), $create_status['results']['remark']], $san_setting['example_link']);
                    # ---------------- check errors ---------------- #
                    if ($create_status['status'] == false) {
                        sendMessage($from_id, sprintf($texts['create_error'], 1), $start_key);
                        throw new Exception("Sanayi test account creation failed: " . json_encode($create_status));
                    }
                    # ---------------------------------------------- #
                    $sql->query("UPDATE `users` SET `count_service` = count_service + 1, `test_account` = 'yes' WHERE `from_id` = '$from_id'");
                    $sql->query("INSERT INTO `test_account` (`from_id`, `location`, `date`, `volume`, `link`, `price`, `code`, `status`) VALUES ('$from_id', '{$panel_fetch['name']}', '{$test_account_setting['date']}', '{$test_account_setting['volume']}', '$link', '0', '$code', 'active')");
                    deleteMessage($from_id, $message_id + 1);
                    sendMessage($from_id, sprintf($texts['create_test_account'], $test_account_setting['time'], $link, $panel_fetch['name'], $test_account_setting['time'], $test_account_setting['volume'], base64_encode($code)), $start_key);
                }
            } catch (\Throwable $e) {
                sendMessage($config['dev'], $e);
            }
        } else {
            sendMessage($from_id, $texts['already_test_account'], $start_key);
        }
    } elseif (
        $text == '🛍 سرویس های من'
        or in_array($data, array('^my_services_menu', '^back_my_services_menu_from_all_services'))
        or strpos($data, '^my_services_menu') !== false
    ) {
        step('none');
        $key = [
            [
                [
                    'text' => 'جستجو 🔍',
                    'callback_data' => 'search_service'
                ]
            ],
            [
                [
                    'text' => 'وضعیت سرویس ها 🚥',
                    'callback_data' => '^stat_lst'
                ],
                [
                    'text' => 'نمایش سرویس ها 👥',
                    'callback_data' => "^loc_lst@my_services_menu {$service_status_map_status_to_index['all']}"
                ]
            ],
            [
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => 'back_to_home'
                ]
            ]
        ];
        if (isset($text)) {
            sendMessage($from_id, $texts['my_services'], json_encode(['inline_keyboard' => $key]));
        } else {
            editMessage($from_id, $texts['my_services'], $message_id, json_encode(['inline_keyboard' => $key]));
            if ($data == '^back_my_services_menu_from_all_services') {
                $file_name = $from_id . "-" . "all_services_lists_msg_ids";
                $service_lists_msg_ids_string = read_temp_file($file_name)[0];
                $service_lists_msg_ids = explode('-', $service_lists_msg_ids_string);
                if (($key = array_search($message_id, $service_lists_msg_ids)) !== false) {
                    unset($service_lists_msg_ids[$key]);
                }
                deleteMessages($from_id, $service_lists_msg_ids);
                delete_temp_file($file_name);
            }
        }
    } elseif ($data == '^stat_lst') {
        // editMessage($from_id)
        $key = [
            [
                [
                    'text' => $texts["status__active"],
                    'callback_data' => "^loc_lst@stat_lst {$service_status_map_status_to_index['active']}"
                ],
                [
                    'text' => $texts["status__expired"],
                    'callback_data' => "^loc_lst@stat_lst {$service_status_map_status_to_index['expired']}"
                ]

            ],
            [
                [
                    'text' => $texts["status__disabled"],
                    'callback_data' => "^loc_lst@stat_lst {$service_status_map_status_to_index['disabled']}"
                ],
                [
                    'text' => $texts["status__limited"],
                    'callback_data' => "^loc_lst@stat_lst {$service_status_map_status_to_index['limited']}"
                ]
            ],
            // [
            //     [
            //         'text' => $texts["status__on_hold"],
            //         'callback_data' => "^loc_lst@stat_lst {$service_status_map_status_to_index['on_hold']}"
            //     ]
            // ],
            [
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => '^my_services_menu'
                ]
            ]
        ];
        editMessage($from_id, $texts['my_services'], $message_id, json_encode(['inline_keyboard' => $key]));
    } elseif (in_array($data, array('search_service'))) {
        $key = [
            [
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => '^my_services_menu'
                ]
            ],
        ];
        if (isset($text)) {
            sendMessage($from_id, $texts['service_search'], json_encode(['inline_keyboard' => $key]));
        } else {
            editMessage($from_id, $texts['service_search'], $message_id, json_encode(['inline_keyboard' => $key]));
        }
        step('search-service');
    } elseif ($user['step'] == 'search-service') {
        $service_base_name = $text;
        $services = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$from_id' AND `code` = '$service_base_name'");
        if ($services->num_rows > 0) {
            // sendMessage($from_id, json_encode($services, 448)); // =========================
            step('none');
            while ($row = $services->fetch_assoc()) {
                $service_base_name = $row['code'];
                $service_name = $row['code'] . "_" . $from_id;
                $service_location = $row['location'];
                $mysql_service_panel = $sql->query("SELECT `row`, `name`, `login_link` FROM `panels` WHERE `name` = '$service_location'")->fetch_assoc();
                $panel_row = intval($mysql_service_panel['row']);
                $getUserInfoRes = getUserInfo($service_name, get_marzban_panel_token($service_location), $mysql_service_panel['login_link']);

                switch ($getUserInfoRes[2]['status']) {
                    case "active":
                        $status = $texts['sign__active'];
                        $status_index = $service_status_map_status_to_index['active'];
                        break;
                    case "disabled":
                        $status = $texts['sign__disabled'];
                        $status_index = $service_status_map_status_to_index['disabled'];
                        break;
                    case "limited":
                        $status = $texts['sign__limited'];
                        $status_index = $service_status_map_status_to_index['limited'];
                        break;
                    case "expired":
                        $status = $texts['sign__expired'];
                        $status_index = $service_status_map_status_to_index['expired'];
                        break;
                    case "on_hold":
                        $status = $texts['sign__on_hold'];
                        $status_index = $service_status_map_status_to_index['on_hold'];
                        break;
                    default:
                        if ($getUserInfoRes[0] == false) {
                            $status = '❗️';
                        } elseif ($getUserInfoRes[1] == false) {
                            $status = $texts['sign__deleted'];
                        } else {
                            $status = '❗️❗️';
                        }
                        break;
                }
                $key[] = [
                    'text' => $status . $row['code'] . ' - ' . $mysql_service_panel['name'],
                    'callback_data' => "^serv_stat@my_services_menu 0 {$status_index} {$panel_row} {$current_list_index}"
                ];
                write_temp_file(json_encode([$service_base_name], 448), "srv-list--{$from_id}.txt", FALSE);
            }
            $found_services_keys = array_chunk($key, 1);
            $found_services_keyboard = json_encode(['inline_keyboard' => $found_services_keys]);

            sendMessage($from_id, $texts['service_search_result'], $found_services_keyboard);
        } else {
            $key = [
                [
                    [
                        'text' => '🔙 بازگشت',
                        'callback_data' => '^my_services_menu'
                    ]
                ],
            ];
            sendMessage($from_id, $texts['service_search_retry'], json_encode(['inline_keyboard' => $key]));
            step('search-service');
        }
    } elseif (strpos($data, '^loc_lst@') !== false) {
        $callback_parts = explode(' ', $data);
        $previous_menu = substr($callback_parts[0], strpos($callback_parts[0], "@") + 1);
        $status_index = $callback_parts['1'];
        $inline_keys = [];
        foreach ($sql->query("SELECT `row`, `name` FROM `panels`")->fetch_all(MYSQLI_ASSOC) as $panel) {
            $inline_keys[] = [
                [
                    'text' => $panel['name'],
                    // 'callback_data' => "{$data} {$panel['name']} 0"
                    'callback_data' => "^srv_lst@loc_lst@{$previous_menu} {$status_index} {$panel['row']} 0"
                    // 'callback_data' => "serv_stat-{$service_base_name}-serv_list__{$current_list_index}"
                ]
            ];
        }
        $inline_keys[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "^{$previous_menu}"
            ]
        ];
        editMessage($from_id, $texts['my_services_locations'], $message_id, json_encode(['inline_keyboard' => $inline_keys]));
    } elseif (strpos($data, '^srv_lst@') !== false) {
        $callback_parts = explode(' ', $data);
        $previous_menu = substr($callback_parts[0], strpos($callback_parts[0], "@") + 1);
        $list_status_type_index = intval($callback_parts[1]);
        $list_status_type = $service_status_map_index_to_status[$list_status_type_index];
        $panel_row = intval($callback_parts[2]);
        $panel_name = $sql->query("SELECT `name` FROM `panels` WHERE `row` = {$panel_row}")->fetch_all(MYSQLI_ASSOC)[0]['name'];
        $current_list_index = intval($callback_parts[3]);
        $max_list_length = 20; // todo: configurable
        $list_offset = $current_list_index * $max_list_length;
        $list_end = $list_offset + $max_list_length;
        
        switch ($list_status_type) {
            case "all":
                $list_status_type__text = $texts["status__all"];
                $api_status_parameter = null;
                break;
            case "active":
                $list_status_type__text = $texts["status__active"];
                $api_status_parameter = "active";
                break;
            case "disabled":
                $list_status_type__text = $texts["status__disabled"];
                $api_status_parameter = "disabled";
                break;
            case "limited":
                $list_status_type__text = $texts["status__limited"];
                $api_status_parameter = "limited";
                break;
            case "expired":
                $list_status_type__text = $texts["status__expired"];
                $api_status_parameter = "expired";
                break;
            case "on_hold":
                $list_status_type__text = $texts["status__on_hold"];
                $api_status_parameter = "on_hold";
                break;
            default:
                $list_status_type__text = 'UNKNOWN';
                $api_status_parameter = null;
        }

        //? go_service_list__<TOTAL_SERVICE_COUNT>__<SERVICE_LIST_INDEX>
        //? back_service_list__<TOTAL_SERVICE_COUNT>__<SERVICE_LIST_INDEX>
        // $callback_parts = explode('__', $data);
        // if (count($callback_parts) > 1) {
        //     $current_list_index = intval($callback_parts[1]);
        // } else {
        //     $current_list_index = 0;
        // }
        $cuurent_list_number = $current_list_index + 1;

        if ($cuurent_list_number < 1) {
            alert($texts["show_service_list__error__no_service_list_available"]);
            exit(0);
        }

        $bot_db__user_services = $sql->query(
            "SELECT `code`, `location`, `row` FROM `orders` 
            WHERE `from_id` = '$from_id' AND `location` = '$panel_name'
            ORDER BY `row` DESC"
            // LIMIT $max_list_length OFFSET $starting_service_row"
        );

        $bot_db__user_services__count = $bot_db__user_services->num_rows;
        if ($bot_db__user_services__count == 0) {
            if (isset($text)) {
                sendMessage($from_id, $texts['my_services_not_found'], $start_key);
            } else {
                editMessage($from_id, $texts['my_services_not_found'], $message_id, $start_key);
            }
        }

        $user__service_names = [];
        foreach ($bot_db__user_services->fetch_all(MYSQLI_ASSOC) as $row) {
            $service_base_name = $row['code'];
            $service_name = $service_base_name . "_" . $from_id;
            $user__service_names[] = $service_name;
        }

        $panel_login_link = $sql->query("SELECT `login_link` FROM `panels` WHERE `row` = '$panel_row'")->fetch_all(MYSQLI_ASSOC)[0]['login_link'];
        $panel_token = get_marzban_panel_token($panel_name);

        $services_name_batches=[];
        $batch_size = 50; // todo: configurable
        for ($i=0; $i < count($user__service_names); $i += $batch_size) { 
            $services_name_batches[] = array_slice($user__service_names, $i, $batch_size);
        }

        $panel__total_services_found_count = 0;
        $panel__current_list_services_found_count = 0;
        $current_list_services= [];
        foreach ($services_name_batches as $services_name_batch) {
            $panel_res = getUsersInfo($panel_token, $panel_login_link, null, null, $api_status_parameter, $services_name_batch);
            if ($panel_res['success'] == false) {
                throw new Exception($panel_res['error'], 1);
            }
            $panel_data = $panel_res['data'];
            for ($i = 0; $i < count($panel_data['users']); $i++) {
                $panel__total_services_found_count++;
                if ($panel__total_services_found_count > $list_offset && $panel__total_services_found_count <= ($list_offset + $max_list_length)) {
                    $panel__current_list_services_found_count++;
                    $service_info = $panel_data['users'][$i];
                    $current_list_services[] = $service_info;
                }
            }
        }
        $panel__total_lists_count = ceil($panel__total_services_found_count / $max_list_length);

        if ($panel__current_list_services_found_count === 0) {
            alert($texts["show_service_list__error__no_service_list_available"]);
            exit(0);
        }

        $list_keys = [];
        $base_names_index_mapper = [];
        foreach ($current_list_services as $service_info) {
            $status = '(UNK) ';
            switch ($service_info['status']) {
                case "active":
                    $status = $texts['sign__active'];
                    break;
                case "disabled":
                    $status = $texts['sign__disabled'];
                    break;
                case "limited":
                    $status = $texts['sign__limited'];
                    break;
                case "expired":
                    $status = $texts['sign__expired'];
                    break;
                case "on_hold":
                    $status = $texts['sign__on_hold'];
                    break;
                default:
                    break;
            }
            $service_base_name = substr($service_info['username'], 0, -strlen("_{$from_id}"));

            $base_names_index_mapper[] = $service_base_name;
            $base_name_index = array_search($service_base_name, $base_names_index_mapper);
            $list_keys[] = [
                'text' => "{$status}{$service_base_name} - {$panel_name}",
                'callback_data' => "^serv_stat@srv_lst@{$previous_menu} {$base_name_index} {$list_status_type_index} {$panel_row} {$current_list_index}"
            ];
            // $service_base_name = explode('__', $data);
            // if ($token_acccepted == false){
            //     $status = '❗️';
            // } elseif ($user_existed == false){
            //     $status = '❌';
            $prossessed_services_count++;
        }

        write_temp_file(json_encode($base_names_index_mapper, 448), "srv-list--{$from_id}.txt", FALSE);
        $current_list_buttons = array_chunk($list_keys, 1);
        $service_list_number = $current_list_index + 1;

        $current_list_buttons[] = [
            [
                'text' => 'لیست' . ' ' . $service_list_number,
                'callback_data' => 'null'
            ]
        ];

        $current_list_buttons[] = [
            [
                'text' => $texts["show_service_list__button__previous_list"],
                'callback_data' => "^srv_lst@{$previous_menu} {$list_status_type_index} {$panel_row} " . intval($current_list_index - 1)
            ],
            [
                'text' => $texts["show_service_list__button__next_list"],
                'callback_data' => "^srv_lst@{$previous_menu} {$list_status_type_index} {$panel_row} " . intval($current_list_index + 1)
            ]
        ];

        $current_list_buttons[] = [
            [
                'text' => $texts["show_service_list__button__5_previous_list"],
                'callback_data' => "^srv_lst@{$previous_menu} {$list_status_type_index} {$panel_row} " . intval($current_list_index - 5)
            ],
            [
                'text' => $texts["show_service_list__button__5_next_list"],
                'callback_data' => "^srv_lst@{$previous_menu} {$list_status_type_index} {$panel_row} " . intval($current_list_index + 5)
            ]
        ];

        $current_list_buttons[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "^{$previous_menu} {$list_status_type_index}"
            ]
        ];

        if ($list_status_type != "all") {
            $valid_services_count = $texts['not_available'];
        } else {
            $valid_services_count = $panel__total_services_found_count;
        }

        $reply_msg = sprintf($texts['service_list'], $panel_name, $list_status_type__text, $bot_db__user_services__count, $panel__total_lists_count, count($list_keys));
        editMessage($from_id, $reply_msg, $message_id, json_encode(['inline_keyboard' => $current_list_buttons]));
        //     } elseif (
        //         $data == 'all_services'
        //         or strpos($data, 'back_all_services') !== false
        //         or strpos($data, 'go_serv_list__') !== false
        //         or strpos($data, 'back_serv_list__') !== false
        //     ) {
        //         $max_list_length = 20;
        //         //? go_service_list__<TOTAL_SERVICE_COUNT>__<SERVICE_LIST_INDEX>
        //         //? back_service_list__<TOTAL_SERVICE_COUNT>__<SERVICE_LIST_INDEX>
        //         $callback_parts = explode('__', $data);
        //         if (count($callback_parts) > 1) {
        //             $total_services_count = intval($callback_parts[1]);
        //             $current_list_index = intval($callback_parts[2]);
        //             $starting_service_row = $current_list_index * $max_list_length;
        //         } else {
        //             $current_list_index = 0;
        //             $starting_service_row = 0;
        //             $total_services_count = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$from_id'")->num_rows;
        //         }

        //         $cuurent_list_number = $current_list_index + 1;
        //         $total_lists_count = ceil($total_services_count / $max_list_length);

        //         if ($cuurent_list_number < 1 or $cuurent_list_number > $total_lists_count) {
        //             alert($texts["show_service_list__error__no_service_list_available"]);
        //             exit(0);
        //         }

        //         $current_list_services = $sql->query(
        //             "SELECT `code`, `location`, `row`  
        //             FROM `orders` 
        //             WHERE `from_id` = '$from_id'
        //             ORDER BY `row` DESC LIMIT $max_list_length OFFSET $starting_service_row"
        //         );

        //         $current_list_services_count = $current_list_services->num_rows;
        //         if ($current_list_index != 0 and $current_list_services_count == 0) {
        //             alert("nos service");

        //         } elseif ($current_list_services_count > 0 or $current_list_index != 0) {
        //             $serviceLocationMap = array();
        //             foreach ($sql->query("SELECT `name`, `login_link` FROM `panels`")->fetch_all(MYSQLI_ASSOC) as $row) {
        //                 // $serviceLocationMap[$row['name']] = [
        //                 //     'login_link' => $row['login_link'],
        //                 //     'token' => $row['token']
        //                 // ];
        //                 $serviceLocationMap[$row['name']] = $row['login_link'];
        //             }

        //             $services_base_details = [];
        //             $curlMultiHandle = curl_multi_init();
        //             $curlHandles = array();
        //             $i = 0;

        //             foreach ($current_list_services->fetch_all(MYSQLI_ASSOC) as $row) {
        //                 $service_base_name = $row['code'];
        //                 $service_name = $service_base_name . "_" . $from_id;
        //                 $service_location = $row['location'];

        //                 $services_base_details[] = [
        //                     "service_name" => $service_name,
        //                     "service_base_name" => $service_base_name,
        //                     "service_location" => $service_location,
        //                 ];

        //                 // $api_url = $serviceLocationMap[$service_location]['login_link'] . '/api/user/' . $service_name;
        //                 $api_url = $serviceLocationMap[$service_location] . '/api/user/' . $service_name;
        //                 $curlHandle = curl_init();
        //                 $req_headers = array(
        //                     'Accept: application/json',
        //                     'Authorization: Bearer ' . get_marzban_panel_token($service_location),

        //                 );
        //                 curl_setopt($curlHandle, CURLOPT_URL, $api_url);
        //                 curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false);
        //                 curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, false);
        //                 curl_setopt($curlHandle, CURLOPT_HTTPGET, true);
        //                 curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        //                 curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $req_headers);
        //                 curl_multi_add_handle($curlMultiHandle, $curlHandle);
        //                 $curlHandles[$i] = $curlHandle;
        //                 $i++;
        //             }

        //             $running = null;
        //             do {
        //                 curl_multi_exec($curlMultiHandle, $running);
        //             } while ($running > 0);

        //             foreach ($curlHandles as $i => $curlHandle) {
        //                 $curlResponse = json_decode(curl_multi_getcontent($curlHandle), true);
        //                 $serviceStatus = $curlResponse['status'];

        //                 if (
        //                     isset($curlResponse['detail'])
        //                     && in_array($curlResponse['detail'], ['Could not validate credentials', 'Not authenticated'])
        //                 ) {
        //                     $token_acccepted = false;
        //                     $user_existed = null;
        //                 } else if (isset($curlResponse['username'])) {
        //                     $token_acccepted = true;
        //                     $user_existed = true;
        //                 } else {
        //                     $token_acccepted = true;
        //                     $user_existed = false;
        //                 }

        //                 if ($token_acccepted == false){
        //                     $status = '❗️';
        //                 } elseif ($user_existed == false){
        //                     $status = '❌';
        //                 } elseif ($serviceStatus == 'active') {
        //                     $status = '🟢';
        //                 } elseif (in_array($serviceStatus, array("disabled", "limited", "expired"))) {
        //                     $status = '🔴';
        //                 } else {
        //                     $status = '🔴❗️🔴';
        //                 }
        //                 $services_base_details[$i]['status'] = $status;

        //                 curl_multi_remove_handle($curlMultiHandle, $curlHandle);
        //                 curl_close($curlHandle);
        //             }

        //             curl_multi_close($curlMultiHandle);

        //             $list_keys = array();
        //             for ($i = 0; $i < count($services_base_details); $i++) {
        //                 $service_base_datails = $services_base_details[$i];
        //                 $service_base_name = $service_base_datails['service_base_name'];
        //                 $service_name = $service_base_datails['service_name'];
        //                 $service_location = $service_base_datails['service_location'];
        //                 $service_list_index = $current_list_index;
        //                 $service_status = $service_base_datails['status'];

        //                 $key = [
        //                     'text' => $service_status . $service_base_name . ' - ' . $service_location,
        //                     'callback_data' => 'serv_stat-' . $service_base_name . "-" . 'serv_list' . '__' . $total_services_count . '__' . $current_list_index
        //                 ];
        //                 $list_keys[] = $key;
        //             }

        //             $current_list_buttons = array_chunk($list_keys, 1);
        //             $service_list_number = $current_list_index + 1;
        //             // $list_indicator_button = [
        //             //     [
        //             //         'text' => 'لیست' . ' ' . $service_list_number,
        //             //         'callback_data' => 'null'
        //             //     ]
        //             // ];

        //             // array_unshift($current_list_buttons, $list_indicator_button);

        //             // $is_first_list = $current_list_index == 0;
        //             // $is_last_list = $service_list_number == $total_lists_count;

        //             // if ($is_first_list and $is_last_list) {
        //             //     $current_list_buttons[] = [
        //             //         [
        //             //             'text' => '🚫',
        //             //             'callback_data' => 'no_service_list_available'
        //             //         ],
        //             //         [
        //             //             'text' => '🚫',
        //             //             'callback_data' => 'no_service_list_available'
        //             //         ]
        //             //     ];
        //             // } elseif ($is_first_list) {
        //             //     $current_list_buttons[] = [
        //             //         [
        //             //             'text' => '🚫',
        //             //             'callback_data' => 'no_service_list_available'
        //             //         ],
        //             //         [
        //             //             'text' => $texts["show_service_list__button__next_list"],
        //             //             'callback_data' => 'go_service_list__total_' . $total_services_count . '__index_' . $current_list_index + 1
        //             //         ]
        //             //     ];
        //             // } elseif ($is_last_list) {
        //             //     if ($current_list_services_count == 0) {
        //             //         $current_list_buttons[] = [
        //             //             [
        //             //                 'text' => $texts["show_service_list__button__previous_list"],
        //             //                 'callback_data' => 'go_service_list__total_' . $total_services_count . '__index_' . '0'
        //             //             ],

        //             //             [
        //             //                 'text' => '🚫',
        //             //                 'callback_data' => 'no_service_list_available'
        //             //             ]
        //             //         ];
        //             //     } else {
        //             //         $current_list_buttons[] = [
        //             //             [
        //             //                 'text' => $texts["show_service_list__button__previous_list"],
        //             //                 'callback_data' => 'go_service_list__total_' . $total_services_count . '__index_' . intval($current_list_index - 1)
        //             //             ],
        //             //             [
        //             //                 'text' => '🚫',
        //             //                 'callback_data' => 'no_service_list_available'
        //             //             ]
        //             //         ];
        //             //     }
        //             // } else {
        //             //     $current_list_buttons[] = [
        //             //         [
        //             //             'text' => $texts["show_service_list__button__previous_list"],
        //             //             'callback_data' => 'go_service_list__total_' . $total_services_count . '__index_' . intval($current_list_index - 1)
        //             //         ],
        //             //         [
        //             //             'text' => $texts["show_service_list__button__next_list"],
        //             //             'callback_data' => 'go_service_list__total_' . $total_services_count . '__index_' . $current_list_index + 1
        //             //         ]
        //             //     ];
        //             // }
        //             $current_list_buttons[] = [
        //                 [
        //                     'text' => 'لیست' . ' ' . $service_list_number,
        //                     'callback_data' => 'null'
        //                 ]
        //             ];
        //             $current_list_buttons[] = [
        //                 [
        //                     'text' => $texts["show_service_list__button__previous_list"],
        //                     'callback_data' => 'go_serv_list__' . $total_services_count . '__' . intval($current_list_index - 1)
        //                 ],
        //                 [
        //                     'text' => $texts["show_service_list__button__next_list"],
        //                     'callback_data' => 'go_serv_list__' . $total_services_count . '__' . intval($current_list_index + 1)
        //                 ]
        //             ];

        //             $current_list_buttons[] = [
        //                 [
        //                     'text' => $texts["show_service_list__button__5_previous_list"],
        //                     'callback_data' => 'go_serv_list__' . $total_services_count . '__' . intval($current_list_index - 5)
        //                 ],
        //                 [
        //                     'text' => $texts["show_service_list__button__5_next_list"],
        //                     'callback_data' => 'go_serv_list__' . $total_services_count . '__' . intval($current_list_index + 5)
        //                 ]
        //             ];

        //             $current_list_buttons[] = [
        //                 [
        //                     'text' => '🔙 بازگشت',
        //                     'callback_data' => '^my_services_menu'
        //                 ]
        //             ];

        //             $reply_msg = sprintf($texts['all_services'], $total_services_count, $total_lists_count, count($list_keys));
        //             editMessage($from_id, $reply_msg, $message_id, json_encode(['inline_keyboard' => $current_list_buttons]));
        //         } else {
        //             if (isset($text)) {
        //                 sendMessage($from_id, $texts['my_services_not_found'], $start_key);
        //             } else {
        //                 editMessage($from_id, $texts['my_services_not_found'], $message_id, $start_key);
        //             }
        //         }
    } elseif (strpos($data, '^serv_stat@') !== false) {
        //? service_status-<USER>-service_list__total_<TOTAL_SERVICE_COUNT>__index_<SERVICE_LIST_INDEX>
        "^serv_stat@srv_lst@{$previous_menu} {$service_base_name} {$list_status_type_index} {$panel_row} {$current_list_index}";

        $callback_parts = explode(' ', $data);
        $previous_menu = substr($callback_parts[0], strpos($callback_parts[0], "@") + 1);
        $code_base_index = intval($callback_parts[1]);
        $code_base = read_temp_file("srv-list--{$from_id}.txt", TRUE)[0][$code_base_index];
        delete_temp_file("srv-list--{$from_id}.txt");
        $code = "{$code_base}_{$from_id}";

        $getService = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code_base' AND `from_id` = '$from_id'")->fetch_assoc();

        if ($getService['type'] == 'marzban') {

            $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '{$getService['location']}'")->fetch_assoc();
            $getUserInfoRes = getUserInfo($code, get_marzban_panel_token($getService['location']), $panel['login_link']);
            $getUser = $getUserInfoRes[2];

            if ($getUserInfoRes[0] == false) {
                step('none');
                alert($my_texts['error_show_service__token_is_incorrect']);
                exit();
            } else if ($getUserInfoRes[1] == false) {
                step('none');
                alert($my_texts['error_show_service__config_not_found']);
                $sql->query("DELETE FROM `orders` WHERE `code` = '$code_base' AND `from_id` = '$from_id'");
                exit();
            };

            // if ($getUserInfoRes[0] == false) {
            //     // $reset_token_result = reset_panel_panel_token($panel['name'], $panel['login_link']);
            //     $reset_token_result = get_marzban_panel_token($panel['name']);
            //     if ($reset_token_result !== false) {
            //         $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '{$getService['location']}'")->fetch_assoc();
            //         $getUserInfoRes = getUserInfo($code, get_marzban_panel_token($getService['location']), $panel['login_link']);
            //         $getUser = $getUserInfoRes[2];
            //     } else {
            //         alert($my_texts['error_show_service__token_reset_failed']);
            //         exit();
            //     }
            // }
            $status = null;
            switch ($getUser['status']) {
                case "active":
                    $status = $texts['status__active'];
                    break;
                case "disabled":
                    $status = $texts['status__disabled'];
                    break;
                case "limited":
                    $status = $texts['status__limited'];
                    break;
                case "expired":
                    $status = $texts['status__expired'];
                    break;
                case "on_hold":
                    $status = $texts['status__on_hold'];
                    break;
                default:
                    $status = '(UNK) ';
                    break;
            }
            if (isset($getUser['links']) and $getUser != false) {
                $links = implode("\n\n", $getUser['links']) ?? 'NULL';
                $subscribe = (strpos($getUser['subscription_url'], 'http') !== false) ? $getUser['subscription_url'] : $panel['login_link'] . $getUser['subscription_url'];
                $now = new DateTime();
                $expireDateTimeStamp = $getUser['expire'];
                if (isset($expireDateTimeStamp)) {
                    $expireDate = new DateTime("@$expireDateTimeStamp", new DateTimeZone('UTC'));
                    $diffLastOnlineDateTillNow = $now->diff($expireDate);

                    if ($now > $expireDate) {
                        $reply_text_suffix = "قبل";
                    } else {
                        $reply_text_suffix = "دیگر";
                    };

                    if ($diffLastOnlineDateTillNow->y > 0) {
                        $diffLastOnlineDateTillNowString = $diffLastOnlineDateTillNow->format('%y سال و %m ماه');
                        $diffLastOnlineDateTillNowString = $diffLastOnlineDateTillNowString . " " . $reply_text_suffix;
                    } elseif ($diffLastOnlineDateTillNow->m > 0) {
                        $diffLastOnlineDateTillNowString = $diffLastOnlineDateTillNow->format('%m ماه و %d روز');
                        $diffLastOnlineDateTillNowString = $diffLastOnlineDateTillNowString . " " . $reply_text_suffix;
                    } elseif ($diffLastOnlineDateTillNow->d > 0) {
                        $diffLastOnlineDateTillNowString = $diffLastOnlineDateTillNow->format('%d روز و %h ساعت');
                        $diffLastOnlineDateTillNowString = $diffLastOnlineDateTillNowString . " " . $reply_text_suffix;
                    } elseif ($diffLastOnlineDateTillNow->h > 0) {
                        $diffLastOnlineDateTillNowString = $diffLastOnlineDateTillNow->format('%h ساعت و %i دقیقه');
                        $diffLastOnlineDateTillNowString = $diffLastOnlineDateTillNowString . " " . $reply_text_suffix;
                    } elseif ($diffLastOnlineDateTillNow->i > 0) {
                        $diffLastOnlineDateTillNowString = $diffLastOnlineDateTillNow->format('%i دقیقه');
                        $diffLastOnlineDateTillNowString = $diffLastOnlineDateTillNowString . " " . $reply_text_suffix;
                    } else {
                        $diffLastOnlineDateTillNowString = "به زودی";
                    };
                } else {
                    $diffLastOnlineDateTillNowString = "⚠️ عدم وجود اطلاعات";
                };
                $lastOnlineDateString = $getUser['online_at'];
                if (isset($lastOnlineDateString)) {
                    $lastOnlineDate = new DateTime($lastOnlineDateString, new DateTimeZone('UTC'));
                    $diffLastOnlineDateTillNow = $now->diff($lastOnlineDate);

                    if ($diffLastOnlineDateTillNow->y > 0) {
                        $online_status = $texts["sign__offline"];
                        $last_online = $diffLastOnlineDateTillNow->format('%y سال قبل');
                    } elseif ($diffLastOnlineDateTillNow->m > 0) {
                        $online_status = $texts["sign__offline"];
                        $last_online = $diffLastOnlineDateTillNow->format('%m ماه قبل');
                    } elseif ($diffLastOnlineDateTillNow->d > 0) {
                        $online_status = $texts["sign__offline"];
                        $last_online = $diffLastOnlineDateTillNow->format('%d روز قبل');
                    } elseif ($diffLastOnlineDateTillNow->h > 0) {
                        $online_status = $texts["sign__offline"];
                        $last_online = $diffLastOnlineDateTillNow->format('%h ساعت و %i دقیقه قبل');
                    } elseif ($diffLastOnlineDateTillNow->i > 0) {
                        $online_status = $texts["sign__offline"];
                        $last_online = $diffLastOnlineDateTillNow->format('%i دقیقه قبل');
                    } else {
                        $online_status = $texts["sign__online"];
                        // $last_online =  $diffLastOnlineDateTillNow->format('%s ثانیه');
                        $last_online = "آنلاین";
                    }
                    $online_status_message = "$online_status $last_online";
                } else {
                    $online_status_message = "⚠️ عدم وجود اطلاعات";
                }
                $call = "^{$previous_menu} {$callback_parts[2]} {$callback_parts[3]} {$callback_parts[4]}";
                $manage_service_btns = json_encode(
                    [
                        'inline_keyboard' => [
                            [['text' => 'دریافت QrCode', 'callback_data' => 'getQrCode-' . $code_base . '-marzban']],
                            [['text' => '🔙 بازگشت', 'callback_data' => $call]]
                        ]
                    ]
                );
                // $diffLastOnlineDateTillNowString = date('Y-m-d H:i:s',  $getUser['expire']);
                // editMessage($from_id, sprintf($texts['your_service'], ($getUser['status'] == 'active') ? "{$texts['sign__active']}🟢 فعال" : '🔴 غیرفعال', $online_status_message, $getService['location'], $code_base, Conversion($getUser['used_traffic'], 'GB'), Conversion($getUser['data_limit'], 'GB'), $diffLastOnlineDateTillNowString, ''), $message_id, $manage_service_btns);
                editMessage($from_id, sprintf($texts['your_service'], $status, $online_status_message, $getService['location'], $code_base, Conversion($getUser['used_traffic'], 'GB'), Conversion($getUser['data_limit'], 'GB'), $diffLastOnlineDateTillNowString, ''), $message_id, $manage_service_btns);
            } else {
                alert($my_texts['error_show_service__server_not_found_internally']);
                $sql->query("DELETE FROM `orders` WHERE `code` = '$code_base' AND `from_id` = '$from_id'");
                // alert($texts['not_found_service']);
            };
        } elseif ($panel['type'] == 'sanayi') {

            include_once 'api/sanayi.php';
            $san_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel['code']}'")->fetch_assoc();
            $xui = new Sanayi($panel['login_link'], $panel['token']);
            $getUser = $xui->getUserInfo(base64_encode($code) . '_' . $from_id, $san_setting['inbound_id']);
            $getUser = json_decode($getUser, true);
            if ($getUser['status']) {
                $note = $sql->query("SELECT * FROM `notes` WHERE `code` = '$code' AND `from_id` = '$from_id'");
                $order = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code' AND `from_id` = '$from_id'")->fetch_assoc();
                $link = $order['link'];

                $manage_service_btns = json_encode([
                    'inline_keyboard' => [
                        [['text' => 'دریافت QrCode', 'callback_data' => 'getQrCode-' . $code . '-sanayi']],
                        [['text' => '🔙 بازگشت', 'callback_data' => $callback_parts[2]]]
                    ]
                ]);

                if ($note->num_rows == 0) {
                    editMessage($from_id, sprintf($texts['your_service'], ($getUser['result']['enable'] == true) ? '🟢 فعال' : '🔴 غیرفعال', null, $getService['location'], base64_encode($code), Conversion($getUser['result']['up'] + $getUser['result']['down'], 'GB'), ($getUser['result']['total'] == 0) ? 'نامحدود' : Conversion($getUser['result']['total'], 'GB') . ' MB', date('Y-d-m H:i:s', $getUser['result']['expiryTime']), $link), $message_id, $manage_service_btns);
                } else {
                    $note = $note->fetch_assoc();
                    editMessage($from_id, sprintf($texts['your_service_with_note'], ($getUser['result']['enable'] == true) ? '🟢 فعال' : '🔴 غیرفعال', $note['note'], $getService['location'], base64_encode($code), Conversion($getUser['result']['up'] + $getUser['result']['down'], 'GB'), ($getUser['result']['total'] == 0) ? 'نامحدود' : Conversion($getUser['result']['total'], 'GB') . ' MB', date('Y-d-m H:i:s', $getUser['result']['expiryTime']), $link), $message_id, $manage_service_btns);
                }
            } else {
                $sql->query("DELETE FROM `orders` WHERE `code` = '$code' AND `from_id` = '$from_id'");
                alert($texts['not_found_service']);
            }
        }
    } elseif (strpos($data, 'getQrCode') !== false) {
        alert($texts['wait']);

        $code_base = explode('-', $data)[1];
        $code = $code_base . '_' . $from_id;
        $type = explode('-', $data)[2];

        // $getService = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code'")->fetch_assoc();
        $getService = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code_base' AND `from_id` = '$from_id'")->fetch_assoc();
        $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '{$getService['location']}'")->fetch_assoc();


        if ($type == 'marzban') {
            $token = loginPanel($panel['login_link'], $panel['username'], $panel['password'])['access_token'];
            $getUserInfoRes = getUserInfo($code, $token, $panel['login_link']);
            $getUser = $getUserInfoRes[2];
            // $getUser = getUserInfo(base64_encode($code) . '_' . $from_id, $token, $panel['login_link']);
            if (isset($getUser['links']) and $getUser != false) {
                $subscribe = (strpos($getUser['subscription_url'], 'http') !== false) ? $getUser['subscription_url'] : $panel['login_link'] . $getUser['subscription_url'];
                $encode_url = urldecode($subscribe);
                bot('sendPhoto', ['chat_id' => $from_id, 'photo' => "https://api.qrserver.com/v1/create-qr-code/?data=$encode_url&size=800x800", 'caption' => "", 'parse_mode' => 'html']);
            } else {
                alert('❌ Error', true);
            }
        } elseif ($type == 'sanayi') {
            $order = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code' AND `from_id` = '$from_id'")->fetch_assoc();
            $link = $order['link'];
            $encode_url = urlencode($link);
            bot('sendPhoto', ['chat_id' => $from_id, 'photo' => "https://api.qrserver.com/v1/create-qr-code/?data=$encode_url&size=800x800", 'caption' => "", 'parse_mode' => 'html']);
        } else {
            alert('❌ Error -> not found type !', true);
        }
    } elseif (strpos($data, 'write_note') !== false) {
        $code = explode('-', $data)[1];
        $type = explode('-', $data)[2];
        step('set_note-' . $code . '-' . $type);
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, sprintf($texts['send_note'], $code), $back);
    } elseif (strpos($user['step'], 'set_note') !== false) {
        $code = explode('-', $user['step'])[1];
        $type = explode('-', $user['step'])[2];
        if ($sql->query("SELECT `code` FROM `notes` WHERE `code` = '$code'")->num_rows == 0) {
            $sql->query("INSERT INTO `notes` (`note`, `code`, `type`, `status`) VALUES ('$text', '$code', '$type', 'active')");
        } else {
            $sql->query("UPDATE `notes` SET `note` = '$text' WHERE `code` = '$code'");
        }
        sendMessage($from_id, sprintf($texts['set_note_success'], $code), $start_key);
    } elseif (strpos($data, 'buy_extra_time') !== false) {
        $code = explode('-', $data)[1];
        $type = explode('-', $data)[2];
        $category_date = $sql->query("SELECT * FROM `category_date` WHERE `status` = 'active'");

        if ($category_date->num_rows > 0) {
            while ($row = $category_date->fetch_assoc()) {
                $key[] = ['text' => $row['name'], 'callback_data' => 'select_extra_time-' . $row['code'] . '-' . $code];
            }
            $key = array_chunk($key, 2);
            $key[] = [['text' => '🔙 بازگشت', 'callback_data' => 'serv_stat-' . $code]];
            $key = json_encode(['inline_keyboard' => $key]);
            editMessage($from_id, sprintf($texts['select_extra_time_plan'], $code), $message_id, $key);
        } else {
            alert($texts['not_found_plan_extra_time'], true);
        }
    } elseif (strpos($data, 'buy_extra_volume') !== false) {
        $code = explode('-', $data)[1];
        $type = explode('-', $data)[2];
        $category_limit = $sql->query("SELECT * FROM `category_limit` WHERE `status` = 'active'");

        if ($category_limit->num_rows > 0) {
            while ($row = $category_limit->fetch_assoc()) {
                $key[] = ['text' => $row['name'], 'callback_data' => 'select_extra_volume-' . $row['code'] . '-' . $code];
            }
            $key = array_chunk($key, 2);
            $key[] = [['text' => '🔙 بازگشت', 'callback_data' => 'serv_stat-' . $code]];
            $key = json_encode(['inline_keyboard' => $key]);
            editMessage($from_id, sprintf($texts['select_extra_volume_plan'], $code), $message_id, $key);
        } else {
            alert($texts['not_found_plan_extra_volume'], true);
        }
    } elseif ($data == 'cancel_buy') {
        step('none');
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, $texts['cancel_extra_factor'], $start_key);
    } elseif (strpos($data, 'select_extra_time') !== false) {
        $service_code = explode('-', $data)[2];
        $plan_code = explode('-', $data)[1];
        $service = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code' AND `from_id` = '$from_id'")->fetch_assoc();
        $plan = $sql->query("SELECT * FROM `category_date` WHERE `code` = '$plan_code'")->fetch_assoc();

        $access_key = json_encode([
            'inline_keyboard' => [
                [['text' => '❌ لغو', 'callback_data' => 'cancel_buy'], ['text' => '✅ تایید', 'callback_data' => 'confirm_extra_time-' . $service_code . '-' . $plan_code]],
            ]
        ]);

        editMessage($from_id, sprintf($texts['create_buy_extra_time_factor'], $service_code, $service_code, $plan['name'], number_format($plan['price']), $service_code), $message_id, $access_key);
    } elseif (strpos($data, 'confirm_extra_time') !== false) {
        alert($texts['wait']);
        $service_code = explode('-', $data)[1];
        $plan_code = explode('-', $data)[2];
        $service = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code' AND `from_id` = '$from_id'")->fetch_assoc();
        $plan = $sql->query("SELECT * FROM `category_date` WHERE `code` = '$plan_code'")->fetch_assoc();
        $getService = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code' AND `from_id` = '$from_id'")->fetch_assoc();
        $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '{$getService['location']}'")->fetch_assoc();

        if ($user['coin'] >= $plan['price']) {
            if ($service['type'] == 'marzban') {
                $token = loginPanel($panel['login_link'], $panel['username'], $panel['password'])['access_token'];
                $getUserInfoRes = getUserInfo(base64_encode($service_code) . '_' . $from_id, $token, $panel['login_link']);
                $getUser = $getUserInfoRes[2];
                $response = Modifyuser(base64_encode($service_code) . '_' . $from_id, array('expire' => $getUser['expire'] += 86400 * $plan['date']), $token, $panel['login_link']);
            } elseif ($service['type'] == 'sanayi') {
                include_once 'api/sanayi.php';
                $panel_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel['code']}'")->fetch_assoc();
                $xui = new Sanayi($panel['login_link'], $panel['token']);
                $getUser = $xui->getUserInfo(base64_encode($service_code) . '_' . $from_id, $panel_setting['inbound_id']);
                $getUser = json_decode($getUser, true);
                if ($getUser['status'] == true) {
                    $response = $xui->addExpire(base64_encode($service_code) . '_' . $from_id, $plan['date'], $panel_setting['inbound_id']);
                    // sendMessage($from_id, $response);
                } else {
                    alert('❌ Error --> not found service');
                }
            }

            $sql->query("UPDATE `users` SET `coin` = coin - {$plan['price']} WHERE `from_id` = '$from_id'");
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, sprintf($texts['success_extra_time'], $plan['date'], $plan['name'], number_format($plan['price'])), $start_key);
        } else {
            alert($texts['not_coin_extra'], true);
        }
    } elseif (strpos($data, 'select_extra_volume') !== false) {
        $service_code = explode('-', $data)[2];
        $plan_code = explode('-', $data)[1];
        $service = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code' AND `from_id` = '$from_id'")->fetch_assoc();
        $plan = $sql->query("SELECT * FROM `category_limit` WHERE `code` = '$plan_code'")->fetch_assoc();

        $access_key = json_encode([
            'inline_keyboard' => [
                [['text' => '❌ لغو', 'callback_data' => 'cancel_buy'], ['text' => '✅ تایید', 'callback_data' => 'confirm_extra_volume-' . $service_code . '-' . $plan_code]],
            ]
        ]);

        editMessage($from_id, sprintf($texts['create_buy_extra_volume_factor'], $service_code, $service_code, $plan['name'], number_format($plan['price']), $service_code), $message_id, $access_key);
    } elseif (strpos($data, 'confirm_extra_volume') !== false) {
        alert($texts['wait']);
        $service_code = explode('-', $data)[1];
        $plan_code = explode('-', $data)[2];
        $service = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code' AND `from_id` = '$from_id'")->fetch_assoc();
        $plan = $sql->query("SELECT * FROM `category_limit` WHERE `code` = '$plan_code'")->fetch_assoc();
        $getService = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code' AND `from_id` = '$from_id'")->fetch_assoc();
        $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '{$getService['location']}'")->fetch_assoc();

        if ($user['coin'] >= $plan['price']) {
            if ($service['type'] == 'marzban') {
                $token = loginPanel($panel['login_link'], $panel['username'], $panel['password'])['access_token'];
                $getUserInfoRes = getUserInfo(base64_encode($service_code) . '_' . $from_id, $token, $panel['login_link']);
                $getUser = $getUserInfoRes[2];
                $response = Modifyuser(base64_encode($service_code) . '_' . $from_id, array('data_limit' => $getUser['data_limit'] += $plan['limit'] * pow(1024, 3)), $token, $panel['login_link']);
            } elseif ($service['type'] == 'sanayi') {
                include_once 'api/sanayi.php';
                $panel_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel['code']}'")->fetch_assoc();
                $xui = new Sanayi($panel['login_link'], $panel['token']);
                $getUser = $xui->getUserInfo(base64_encode($service_code) . '_' . $from_id, $panel_setting['inbound_id']);
                $getUser = json_decode($getUser, true);
                if ($getUser['status'] == true) {
                    $response = $xui->addVolume(base64_encode($service_code) . '_' . $from_id, $plan['limit'], $panel_setting['inbound_id']);
                } else {
                    alert('❌ Error --> not found service');
                }
            }

            $sql->query("UPDATE `users` SET `coin` = coin - {$plan['price']} WHERE `from_id` = '$from_id'");
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, sprintf($texts['success_extra_volume'], $plan['limit'], $plan['name'], number_format($plan['price'])), $start_key);
        } else {
            alert($texts['not_coin_extra'], true);
        }
    } elseif ($text == '💸 شارژ حساب') {
        if ($auth_setting['status'] == 'active') {
            if ($auth_setting['iran_number'] == 'active' or $auth_setting['virtual_number'] == 'active' or $auth_setting['both_number'] == 'active') {
                if (is_null($user['phone'])) {
                    step('authentication');
                    sendMessage($from_id, $texts['send_phone'], $send_phone);
                } else {
                    step('diposet');
                    sendMessage($from_id, $texts['diposet'], $back);
                }
            } else {
                step('diposet');
                sendMessage($from_id, $texts['diposet'], $back);
            }
        } else {
            step('diposet');
            sendMessage($from_id, $texts['diposet'], $back);
        }
    } elseif ($user['step'] == 'authentication') {
        $contact = $update->message->contact;
        if (isset($contact)) {
            if ($contact->user_id == $from_id) {
                if ($auth_setting['iran_number'] == 'active') {
                    if (strpos($contact->phone_number, '+98') !== false) {
                        $sql->query("UPDATE `users` SET `phone` = '{$contact->phone_number}' WHERE `from_id` = '$from_id'");
                        sendMessage($from_id, $texts['send_phone_success'], $start_key);
                    } else {
                        sendMessage($from_id, $texts['only_iran'], $back);
                    }
                } elseif ($auth_setting['virtual_number'] == 'active') {
                    if (strpos($contact->phone_number, '+98') === false) {
                        $sql->query("UPDATE `users` SET `phone` = '{$contact->phone_number}' WHERE `from_id` = '$from_id'");
                        sendMessage($from_id, $texts['send_phone_success'], $start_key);
                    } else {
                        sendMessage($from_id, $texts['only_virtual'], $back);
                    }
                } elseif ($auth_setting['both_number'] == 'active') {
                    $sql->query("UPDATE `users` SET `phone` = '{$contact->phone_number}' WHERE `from_id` = '$from_id'");
                    sendMessage($from_id, $texts['send_phone_success'], $start_key);
                }
            } else {
                sendMessage($from_id, $texts['send_phone_with_below_btn'], $send_phone);
            }
        } else {
            sendMessage($from_id, $texts['send_phone_with_below_btn'], $send_phone);
        }
    } elseif ($user['step'] == 'diposet') {
        if (is_numeric($text) and $text >= 2000) {
            step('sdp-' . $text);
            sendMessage($from_id, sprintf($texts['select_diposet_payment'], number_format($text)), $select_diposet_payment);
        } else {
            sendMessage($from_id, $texts['diposet_input_invalid'], $back);
        }
    } elseif ($data == 'cancel_payment_proccess') {
        step('none');
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, sprintf($texts['greetings'] . $texts['start'], $first_name), $start_key);
    } elseif (in_array($data, ['zarinpal', 'idpay']) and strpos($user['step'], 'sdp-') !== false) {
        if ($payment_setting[$data . '_status'] == 'active') {
            $status = $sql->query("SELECT `{$data}_token` FROM `payment_setting`")->fetch_assoc()[$data . '_token'];
            if ($status != 'none') {
                step('none');
                $price = explode('-', $user['step'])[1];
                $code = rand(11111111, 99999999);
                $sql->query("INSERT INTO `factors` (`from_id`, `price`, `code`, `status`) VALUES ('$from_id', '$price', '$code', 'no')");
                $response = ($data == 'zarinpal') ? zarinpalGenerator($from_id, $price, $code) : idpayGenerator($from_id, $price, $code);
                if ($response)
                    $pay = json_encode(['inline_keyboard' => [[['text' => '💵 پرداخت', 'url' => $response]]]]);
                deleteMessage($from_id, $message_id);
                sendMessage($from_id, sprintf($texts['create_diposet_factor'], $code, number_format($price)), $pay);
                sendMessage($from_id, $texts['back_to_menu'], $start_key);
            } else {
                alert($texts['error_choice_pay']);
            }
        } else {
            alert($texts['not_active_payment']);
        }
    } elseif ($data == 'nowpayment' and strpos($user['step'], 'sdp-') !== false) {
        if ($payment_setting[$data . '_status'] == 'active') {
            alert('⏱ لطفا چند ثانیه صبر کنید.');
            if ($payment_setting[$data . '_status'] == 'active') {
                $code = rand(111111, 999999);
                $price = explode('-', $user['step'])[1];
                $dollar = json_decode(file_get_contents($config['domain'] . '/api/arz.php'), true)['price'];
                $response_gen = nowPaymentGenerator((intval($price) / intval($dollar)), 'usd', 'trx', $code);
                if (!is_null($response_gen)) {
                    $response = json_decode($response_gen, true);
                    $sql->query("INSERT INTO `factors` (`from_id`, `price`, `code`, `status`) VALUES ('$from_id', '$price', '{$response['payment_id']}', 'no')");
                    $key = json_encode(['inline_keyboard' => [[['text' => '✅ پرداخت کردم', 'callback_data' => 'checkpayment-' . $response['payment_id']]]]]);
                    deleteMessage($from_id, $message_id);
                    sendMessage($from_id, sprintf($texts['create_nowpayment_factor'], $response['payment_id'], number_format($price), number_format($dollar), $response['pay_amount'], $response['pay_address']), $key);
                    sendMessage($from_id, $texts['back_to_menu'], $start_key);
                } else {
                    deleteMessage($from_id, $message_id);
                    sendMessage($from_id, $texts['error_nowpayment'] . "\n◽- <code>USDT: $dollar</code>", $start_key);
                }
            } else {
                alert($texts['not_active_payment']);
            }
        } else {
            alert($texts['not_active_payment']);
        }
    } elseif (strpos($data, 'checkpayment') !== false) {
        $payment_id = explode('-', $data)[1];
        $get = checkNowPayment($payment_id);
        $status = json_decode($get, true)['payment_status'];
        if ($status != 'waiting') {
            $factor = $sql->query("SELECT * FROM `factors` WHERE `code` = '$payment_id'")->fetch_assoc();
            if ($factor['status'] == 'no') {
                $sql->query("UPDATE `users` SET `coin` = coin + {$factor['price']}, `count_charge` = count_charge + 1 WHERE `from_id` = '$from_id'");
                $sql->query("UPDATE `factors` SET `status` = 'yes' WHERE `code` = '$payment_id'");
                deleteMessage($from_id, $message_id);
                sendMessage($from_id, sprintf($texts['success_nowpayment'], number_format($factor['price'])), $start_key);
                // sendMessage($config['dev'], $texts['success_payment_notif']);
            } else {
                alert($texts['not_success_nowpayment']);
            }
        } else {
            alert($texts['not_success_nowpayment']);
        }
    } elseif ($data == 'kart') {
        if ($payment_setting['card_status'] == 'active') {
            $price = explode('-', $user['step'])[1];
            step('send_fish-' . $price);
            $code = rand(11111111, 99999999);
            $card_number = $sql->query("SELECT `card_number` FROM `payment_setting`")->fetch_assoc()['card_number'];
            $card_number_name = $sql->query("SELECT `card_number_name` FROM `payment_setting`")->fetch_assoc()['card_number_name'];
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, sprintf($texts['create_kart_factor'], $code, number_format($price), ($card_number != 'none') ? $card_number : '❌ تنظیم نشده', ($card_number_name != 'none') ? $card_number_name : ''), $back);
        } else {
            alert($texts['not_active_payment']);
        }
    } elseif (strpos($user['step'], 'send_fish') !== false) {
        $price = explode('-', $user['step'])[1];
        if (isset($update->message->photo)) {
            step('none');
            $key = json_encode(['inline_keyboard' => [[['text' => '❌', 'callback_data' => 'cancel_fish-' . $from_id], ['text' => '✅', 'callback_data' => 'accept_fish-' . $from_id . '-' . $price]]]]);
            sendMessage($from_id, $texts['success_send_fish'], $start_key);
            sendMessage($config['dev'], sprintf($texts['success_send_fish_notif'], $from_id, $username, $price), $key);
            forwardMessage($from_id, $config['dev'], $message_id);
            if (!is_null($settings['log_channel'])) {
                sendMessage($settings['log_channel'], sprintf($texts['success_send_fish_notif'], $from_id, $username, $price));
                forwardMessage($from_id, $settings['log_channel'], $message_id);
            }
        } else {
            sendMessage($from_id, $texts['error_input_kart'], $back);
        }
    } elseif ($text == '🛒 تعرفه خدمات') {
        sendMessage($from_id, $texts['service_tariff']);
    } elseif ($text == '👤 پروفایل') {
        $services = $sql->query("SELECT `code`, `location` FROM `orders` WHERE `from_id` = '$from_id'");
        $count_all = $services->num_rows;
        $keys = [
            [
                [
                    'text' => $texts['services_overview__button'],
                    'callback_data' => 'all_service_count' . '__' . $from_id
                ]
            ]
        ];
        sendMessage($from_id, sprintf($texts['my_account'], $from_id, number_format($user['coin']), $count_all), json_encode(['inline_keyboard' => $keys]));
    } elseif (strpos($data, 'all_service_count__') !== false) {
        $target_user_id = explode('__', $data)[1];
        $count_all_active = 0;
        $count_all_inactive = 0;
        $overall_unknown_services_count = 0;

        $total_traffic_bought = 0;
        $total_traffic_used = 0;

        $services_query = $sql->query("SELECT `code`, `location` FROM `orders` WHERE `from_id` = '$target_user_id'");
        $service_count = $services_query->num_rows;
        $services = $services_query->fetch_all(MYSQLI_ASSOC) ?: [];

        $serviceLocationMap = array();
        foreach ($sql->query("SELECT `name`, `login_link` FROM `panels`")->fetch_all(MYSQLI_ASSOC) as $row) {
            $serviceLocationMap[$row['name']] = $row['login_link'];
        }

        if ($service_count == 0) {
            alert($texts['services_overview__alert__msg_no_service']);
            return;
        }
        $users_api_url = $serviceLocationMap[$service_location] . '/api/user/' . $service_name;
        for ($i = 0, $len = count($services); $i < $len; $i++) {
            $row = $services[$i];
            $service_base_name = $row['code'];
            $service_name = $service_base_name . "_" . $target_user_id;
            $service_location = $row['location'];
            if (!isset($location_sorted_objs[$service_location])) {
                $location_sorted_objs[$service_location] = [
                    "url" => $serviceLocationMap[$service_location] . '/api/users',
                    "unknown_services" => [],
                    "active_services" => [],
                    "inactive_Services" => []
                ];
            }
            $location_sorted_objs[$service_location]["unknown_services"][$service_name] = $service_base_name;
            $new_user_param = $i === 0 ? "?username=$service_name" : "&username=$service_name";
            $location_sorted_objs[$service_location]["url"] = $location_sorted_objs[$service_location]["url"] . $new_user_param;
        }
        // send_debug_data_to_maintainer(json_encode([$location_sorted_objs], 448), "131757826", true, true);

        foreach ($location_sorted_objs as $service_location_name => $location_detail) {
            $users_api_url = $location_detail['url'];
            send_debug_msg_to_maintainer('time: ' . date('Y-m-d H:i:s'), $from_id, true);
            $token = get_marzban_panel_token($service_location_name);
            send_debug_msg_to_maintainer('time: ' . date('Y-m-d H:i:s'), $from_id, true);
            $panel_res = getUsersInfo($token, $users_api_url);
            if ($panel_res['success'] == false) {
                throw new Exception($panel_res['error'], 1);
            }

            $services_resp = $panel_res['data'];
            $users_data = $services_resp['users'] ?: [];
            foreach ($users_data as $service) {
                $service_username = $service['username'];
                $service_code = $location_sorted_objs[$service_location_name]["unknown_services"][$service_username];

                switch ($service['status']) {
                    case "active":
                        $location_sorted_objs[$service_location_name]['active_services'][$service_username] = $service_code;
                        break;
                    case "disabled":
                        $location_sorted_objs[$service_location_name]['inactive_services'][$service_username] = $service_code;
                        break;
                    case "limited":
                        $location_sorted_objs[$service_location_name]['inactive_services'][$service_username] = $service_code;
                        break;
                    case "expired":
                        $location_sorted_objs[$service_location_name]['inactive_services'][$service_username] = $service_code;
                        break;
                    case "on_hold":
                        break;
                    default:
                        break;
                }
                unset($location_sorted_objs[$service_location_name]["unknown_services"][$service_username]);

                $total_traffic_bought += $service['data_limit'];
                $total_traffic_used += $service['used_traffic'];
            }
            $count_all_active += count($location_sorted_objs[$service_location_name]['active_services']);
            $count_all_inactive += count($location_sorted_objs[$service_location_name]['inactive_services']);
            $overall_unknown_services_count += count($location_sorted_objs[$service_location_name]['unknown_services']);
            // send_debug_data_to_maintainer(json_encode([$req_headers, $test_response, curl_getinfo($ch)], 448), "131757826", true, true);
        }
        # ==== Delete unknown services ==== #
        #! skip this behavior for now as it may cause data loss and unsafe operation
        // foreach ($location_sorted_objs as $service_location_name => $location_detail) {
        //     foreach ($location_detail['unknown_services'] as $service_username => $service_code) {
        //         $query = "DELETE FROM `orders` WHERE `from_id` = '$target_user_id' AND `code` = '$service_code';";
        //         $sql_del_res = $sql->query($query);
        //         if ($sql_del_res !== TRUE || $sql->affected_rows !== 1) {
        //             throw new Exception(json_encode([$target_user_id, $service_code, $query, $sql_del_res, $sql->affected_rows], 448));
        //         }
        //     }
        // }

        // $user_usage = get_users_usage($target_user_id);

        alert(
            sprintf(
                $texts['services_overview__alert__msg'],
                $count_all_active,
                $count_all_inactive,
                $overall_unknown_services_count,
                Conversion($total_traffic_bought, "GB"),
                Conversion($total_traffic_used, "GB")
            )
        );
    } elseif ($text == '📮 پشتیبانی آنلاین') {
        step('support');
        sendMessage($from_id, $texts['support'], $back);
    } elseif ($user['step'] == 'support') {
        step('none');
        sendMessage($from_id, $texts['success_support'], $start_key);
        sendMessage($config['dev'], sprintf($texts['new_support_message'], $from_id, $from_id, $username, $user['coin']), $manage_user);
        forwardMessage($from_id, $config['dev'], $message_id);
    } elseif ($text == '🔗 راهنمای اتصال') {
        step('select_sys');
        sendMessage($from_id, $texts['select_sys'], $education);
    } elseif (strpos($data, 'edu') !== false) {
        $data_parts = explode('_', $data);
        $platform = end($data_parts);

        deleteMessage($from_id, $message_id);
        sendMessage($from_id, $texts['edu_' . $platform], $education);
    }
    # ------------ panel ------------ #
    if ($from_id == $config['dev'] or in_array($from_id, $admin_id_array)) {
        if (in_array($text, ['/panel', 'panel', '🔧 مدیریت', 'پنل', $texts['back_to_bot_management_button']])) {
            step('panel');
            sendMessage($from_id, "👮‍♂️ - سلام ادمین [ <b>$first_name</b> ] عزیز !\n\n⚡️به پنل مدیریت ربات خوش آمدید.\n🗃 ورژن فعلی ربات : <code>{$config['version']}</code>\n\n⚙️ جهت مدیریت ربات ، یکی از گزینه های زیر را انتخاب کنید.", $bot_management_keyboard);
        } elseif ($text == '👥 مدیریت آمار ربات') {
            sendMessage($from_id, "👋 به مدیریت آمار کلی ربات خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید:", $manage_statistics);
        } elseif ($text == '🌐 مدیریت سرور') {
            sendMessage($from_id, "⚙️ به مدیریت پلن ها خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :", $manage_server);
        } elseif ($text == '👤 مدیریت کاربران') {
            sendMessage($from_id, "👤 به مدیریت کاربران خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :", $manage_user);
        } elseif (in_array($text, ['📤 مدیریت پیام', '⬅️ بازگشت به مدیریت پیام ها'])) {
            step('none');
            sendMessage($from_id, "📤 به مدیریت پیام خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :", $manage_message);
        } elseif ($text == '👮‍♂️مدیریت ادمین') {
            sendMessage($from_id, "👮‍♂️ به مدیریت ادمین خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :", $manage_admin);
        } elseif ($text == '⚙️ تنظیمات') {
            sendMessage($from_id, "⚙️️ به تنظیمات ربات خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :", $manage_setting);
        }


        // ----------- do not touch this part ----------- //
        elseif ($text == base64_decode('YmFzZTY0X2RlY29kZQ==')('8J+TniDYp9i32YTYp9i524zZhyDYotm+2K/bjNiqINix2KjYp9iq')) {
            base64_decode('c2VuZE1lc3NhZ2U=')($from_id, base64_decode('8J+QnSB8INio2LHYp9uMINin2LfZhNin2Lkg2KfYsiDYqtmF2KfZhduMINii2b7Yr9uM2Kog2YfYpyDZiCDZhtiz2K7ZhyDZh9in24wg2KjYudiv24wg2LHYqNin2Kog2LLZhtio2YjYsSDZvtmG2YQg2K/YsSDaqdin2YbYp9mEINiy2YbYqNmI2LEg2b7ZhtmEINi52LbZiCDYtNuM2K8gOuKGkwril73vuI9AWmFuYm9yUGFuZWwK8J+QnSB8INmIINmH2YXahtmG24zZhiDYqNix2KfbjCDZhti42LEg2K/Zh9uMINii2b7Yr9uM2Kog24zYpyDYqNin2q8g2YfYpyDYqNmHINqv2LHZiNmHINiy2YbYqNmI2LEg2b7ZhtmEINio2b7bjNmI2YbYr9uM2K8gOuKGkwril73vuI9AWmFuYm9yUGFuZWxHYXAK8J+QnSB8INmG2YXZiNmG2Ycg2LHYqNin2Kog2KLYrtix24zZhiDZhtiz2K7ZhyDYsdio2KfYqiDYstmG2KjZiNixINm+2YbZhCA64oaTCuKXve+4j0BaYW5ib3JQYW5lbEJvdA=='), $bot_management_keyboard);
        }

        // ----------- manage auth ----------- //
        elseif ($text == '🔑 سیستم احراز هویت' or $data == 'manage_auth') {
            if (isset($text)) {
                sendMessage($from_id, "🀄️ به بخش سیستم احراز هویت ربات خوش آمدید !\n\n📚 راهنمای این بخش :↓\n\n🟢 : فعال \n🔴 : غیرفعال", $manage_auth);
            } else {
                editMessage($from_id, "🀄️ به بخش سیستم احراز هویت ربات خوش آمدید !\n\n📚 راهنمای این بخش :↓\n\n🟢 : فعال \n🔴 : غیرفعال", $message_id, $manage_auth);
            }
        } elseif ($data == 'change_status_auth') {
            if ($auth_setting['status'] == 'active') {
                $sql->query("UPDATE `auth_setting` SET `status` = 'inactive'");
            } else {
                $sql->query("UPDATE `auth_setting` SET `status` = 'active'");
            }
            alert('✅ تغییرات با موفقیت انجام شد.', true);
            editMessage($from_id, "🆙 برای آپدیت تغییرات بر روی دکمه زیر کلیک کنید !", $message_id, json_encode(['inline_keyboard' => [[['text' => '🔎 آپدیت تغییرات', 'callback_data' => 'manage_auth']]]]));
        } elseif ($data == 'change_status_auth_iran') {
            if ($auth_setting['status'] == 'active') {
                if ($auth_setting['virtual_number'] == 'inactive' and $auth_setting['both_number'] == 'inactive') {
                    if ($auth_setting['iran_number'] == 'active') {
                        $sql->query("UPDATE `auth_setting` SET `iran_number` = 'inactive'");
                    } else {
                        $sql->query("UPDATE `auth_setting` SET `iran_number` = 'active'");
                    }
                    alert('✅ تغییرات با موفقیت انجام شد.', true);
                    editMessage($from_id, "🆙 برای آپدیت تغییرات بر روی دکمه زیر کلیک کنید !", $message_id, json_encode(['inline_keyboard' => [[['text' => '🔎 آپدیت تغییرات', 'callback_data' => 'manage_auth']]]]));
                } else {
                    alert('⚠️ برای فعال کردن سیستم احراز هویت شماره های ایرانی باید بخش ( 🏴󠁧󠁢󠁥󠁮󠁧󠁿 شماره مجازی ) و ( 🌎 همه شماره ها ) غیرفعال شود !', true);
                }
            } else {
                alert('🔴 برای فعال سازی این بخش ابتدا باید ( ℹ️ سیستم احراز هویت ) را فعال کنید !', true);
            }
        } elseif ($data == 'change_status_auth_virtual') {
            if ($auth_setting['status'] == 'active') {
                if ($auth_setting['iran_number'] == 'inactive' and $auth_setting['both_number'] == 'inactive') {
                    if ($auth_setting['virtual_number'] == 'active') {
                        $sql->query("UPDATE `auth_setting` SET `virtual_number` = 'inactive'");
                    } else {
                        $sql->query("UPDATE `auth_setting` SET `virtual_number` = 'active'");
                    }
                    alert('✅ تغییرات با موفقیت انجام شد.', true);
                    editMessage($from_id, "🆙 برای آپدیت تغییرات بر روی دکمه زیر کلیک کنید !", $message_id, json_encode(['inline_keyboard' => [[['text' => '🔎 آپدیت تغییرات', 'callback_data' => 'manage_auth']]]]));
                } else {
                    alert('⚠️ برای فعال کردن سیستم احراز هویت شماره های مجازی باید بخش ( 🇮🇷 شماره ایران ) و ( 🌎 همه شماره ها ) غیرفعال شود !', true);
                }
            } else {
                alert('🔴 برای فعال سازی این بخش ابتدا باید ( ℹ️ سیستم احراز هویت ) را فعال کنید !', true);
            }
        } elseif ($data == 'change_status_auth_all_country') {
            if ($auth_setting['status'] == 'active') {
                if ($auth_setting['iran_number'] == 'inactive' and $auth_setting['virtual_number'] == 'inactive') {
                    if ($auth_setting['both_number'] == 'active') {
                        $sql->query("UPDATE `auth_setting` SET `both_number` = 'inactive'");
                    } else {
                        $sql->query("UPDATE `auth_setting` SET `both_number` = 'active'");
                    }
                    alert('✅ تغییرات با موفقیت انجام شد.', true);
                    editMessage($from_id, "🆙 برای آپدیت تغییرات بر روی دکمه زیر کلیک کنید !", $message_id, json_encode(['inline_keyboard' => [[['text' => '🔎 آپدیت تغییرات', 'callback_data' => 'manage_auth']]]]));
                } else {
                    alert('⚠️ برای فعال کردن سیستم احراز هویت همه شماره ها باید بخش ( 🇮🇷 شماره ایران ) و ( 🏴󠁧󠁢󠁥󠁮󠁧󠁿 شماره مجازی ) غیرفعال شود !', true);
                }
            } else {
                alert('🔴 برای فعال سازی این بخش ابتدا باید ( ℹ️ سیستم احراز هویت ) را فعال کنید !', true);
            }
        }
        // ----------- manage status ----------- //
        elseif ($text == '👤 آمار ربات') {
            $state1 = $sql->query("SELECT `status` FROM `users`")->num_rows;
            $state2 = $sql->query("SELECT `status` FROM `users` WHERE `status` = 'inactive'")->num_rows;
            $state3 = $sql->query("SELECT `status` FROM `users` WHERE `status` = 'active'")->num_rows;
            $state4 = $sql->query("SELECT `status` FROM `factors` WHERE `status` = 'yes'")->num_rows;
            sendMessage($from_id, "⚙️ آمار ربات شما به شرح زیر می‌باشد :↓\n\n▫️تعداد کل کاربر ربات : <code>$state1</code> عدد\n▫️تعداد کاربر های مسدود : <code>$state2</code> عدد\n▫️تعداد کاربر های آزاد : <code>$state3</code> عدد\n\n🔢 تعداد کل پرداختی : <code>$state4</code> عدد", $manage_statistics);
        }

        // ----------- manage servers ----------- //
        elseif ($text == '❌ انصراف و بازگشت') {
            step('none');
            if (file_exists('add_panel.txt'))
                unlink('add_panel.txt');
            sendMessage($from_id, "⚙️ به مدیریت پلن ها خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :", $manage_server);
        } elseif ($data == 'close_panel') {
            step('none');
            editMessage($from_id, "✅ پنل مدیریت سرور ها با موفقیت بسته شد !", $message_id);
        } elseif ($text == '⏱ مدیریت اکانت تست' or $data == 'back_account_test') {
            step('none');
            // sendMessage($from_id, "{$test_account_setting['status']} - {$test_account_setting['panel']} - {$test_account_setting['volume']} - {$test_account_setting['time']}");
            // exit();
            if (isset($text)) {
                sendMessage($from_id, "⏱ به تنظیمات اکانت تست خوش آمدید.\n\n🟢 حجم را به صورت GB به ربات ارسال کنید | برای مثال 200 مگ : 0.2\n🟢 زمان را به صورت ساعت ارسال کنید | برای مثال 5 ساعت : 5\n\n👇🏻 یکی از گزینه های زیر را انتخاب کنید :", $manage_test_account);
            } else {
                editMessage($from_id, "⏱ به تنظیمات اکانت تست خوش آمدید.\n\n🟢 حجم را به صورت GB به ربات ارسال کنید | برای مثال 200 مگ : 0.2\n🟢 زمان را به صورت ساعت ارسال کنید | برای مثال 5 ساعت : 5\n\n👇🏻 یکی از گزینه های زیر را انتخاب کنید :", $message_id, $manage_test_account);
            }
        } elseif ($data == 'null') {
            alert('#️⃣ این دکمه نمایشی است !');
        } elseif ($data == 'change_test_account_status') {
            $status = $sql->query("SELECT `status` FROM `test_account_setting`")->fetch_assoc()['status'];
            if ($status == 'active') {
                $sql->query("UPDATE `test_account_setting` SET `status` = 'inactive'");
            } else {
                $sql->query("UPDATE `test_account_setting` SET `status` = 'active'");
            }
            $manage_test_account = json_encode([
                'inline_keyboard' => [
                    [['text' => ($status == 'active') ? '🔴' : '🟢', 'callback_data' => 'change_test_account_status'], ['text' => '▫️وضعیت :', 'callback_data' => 'null']],
                    [['text' => ($test_account_setting['panel'] == 'none') ? '🔴 وصل نیست' : '🟢 وصل است', 'callback_data' => 'change_test_account_panel'], ['text' => '▫️متصل به پنل :', 'callback_data' => 'null']],
                    [['text' => $sql->query("SELECT * FROM `test_account`")->num_rows, 'callback_data' => 'null'], ['text' => '▫️تعداد اکانت تست :', 'callback_data' => 'null']],
                    [['text' => $test_account_setting['volume'] . ' GB', 'callback_data' => 'change_test_account_volume'], ['text' => '▫️حجم :', 'callback_data' => 'null']],
                    [['text' => $test_account_setting['time'] . ' ساعت', 'callback_data' => 'change_test_account_time'], ['text' => '▫️زمان :', 'callback_data' => 'null']],
                ]
            ]);
            editMessage($from_id, "⏱ به تنظیمات اکانت تست خوش آمدید.\n\n👇🏻 یکی از گزینه های زیر را انتخاب کنید :", $message_id, $manage_test_account);
        } elseif ($data == 'change_test_account_volume') {
            step('change_test_account_volume');
            editMessage($from_id, "🆕 مقدار جدید را به صورت عدد صحیح ارسال کنید :", $message_id, $back_account_test);
        } elseif ($user['step'] == 'change_test_account_volume') {
            if (isset($text)) {
                if (is_numeric($text)) {
                    step('none');
                    $sql->query("UPDATE `test_account_setting` SET `volume` = '$text'");
                    $manage_test_account = json_encode([
                        'inline_keyboard' => [
                            [['text' => ($status == 'active') ? '🔴' : '🟢', 'callback_data' => 'change_test_account_status'], ['text' => '▫️وضعیت :', 'callback_data' => 'null']],
                            [['text' => ($test_account_setting['panel'] == 'none') ? '🔴 وصل نیست' : '🟢 وصل است', 'callback_data' => 'change_test_account_panel'], ['text' => '▫️متصل به پنل :', 'callback_data' => 'null']],
                            [['text' => $sql->query("SELECT * FROM `test_account`")->num_rows, 'callback_data' => 'null'], ['text' => '▫️تعداد اکانت تست :', 'callback_data' => 'null']],
                            [['text' => $text . ' GB', 'callback_data' => 'change_test_account_volume'], ['text' => '▫️حجم :', 'callback_data' => 'null']],
                            [['text' => $test_account_setting['time'] . ' ساعت', 'callback_data' => 'change_test_account_time'], ['text' => '▫️زمان :', 'callback_data' => 'null']],
                        ]
                    ]);
                    sendMessage($from_id, "✅ عملیات تغییرات با موفقیت انجام شد.\n\n👇🏻 یکی از گزینه های زیر را انتخاب کنید .", $manage_test_account);
                } else {
                    sendMessage($from_id, "❌ ورودی ارسالی اشتباه است !", $back_account_test);
                }
            }
        } elseif ($data == 'change_test_account_time') {
            step('change_test_account_time');
            editMessage($from_id, "🆕 مقدار جدید را به صورت عدد صحیح ارسال کنید :", $message_id, $back_account_test);
        } elseif ($user['step'] == 'change_test_account_time') {
            if (isset($text)) {
                if (is_numeric($text)) {
                    step('none');
                    $sql->query("UPDATE `test_account_setting` SET `time` = '$text'");
                    $manage_test_account = json_encode([
                        'inline_keyboard' => [
                            [['text' => ($status == 'active') ? '🔴' : '🟢', 'callback_data' => 'change_test_account_status'], ['text' => '▫️وضعیت :', 'callback_data' => 'null']],
                            [['text' => ($test_account_setting['panel'] == 'none') ? '🔴 وصل نیست' : '🟢 وصل است', 'callback_data' => 'change_test_account_panel'], ['text' => '▫️متصل به پنل :', 'callback_data' => 'null']],
                            [['text' => $sql->query("SELECT * FROM `test_account`")->num_rows, 'callback_data' => 'null'], ['text' => '▫️تعداد اکانت تست :', 'callback_data' => 'null']],
                            [['text' => $test_account_setting['volume'] . ' GB', 'callback_data' => 'change_test_account_volume'], ['text' => '▫️حجم :', 'callback_data' => 'null']],
                            [['text' => $text . ' ساعت', 'callback_data' => 'change_test_account_time'], ['text' => '▫️زمان :', 'callback_data' => 'null']],
                        ]
                    ]);
                    sendMessage($from_id, "✅ عملیات تغییرات با موفقیت انجام شد.\n\n👇🏻 یکی از گزینه های زیر را انتخاب کنید ", $manage_test_account);
                } else {
                    sendMessage($from_id, "❌ ورودی ارسالی اشتباه است !", $back_account_test);
                }
            }
        } elseif ($data == 'change_test_account_panel') {
            $panels = $sql->query("SELECT * FROM `panels`");
            if ($panels->num_rows > 0) {
                step('change_test_account_panel');
                while ($row = $panels->fetch_assoc()) {
                    $key[] = [['text' => $row['name'], 'callback_data' => 'select_test_panel-' . $row['code']]];
                }
                $key[] = [['text' => '🔙 بازگشت', 'callback_data' => 'back_account_test']];
                $key = json_encode(['inline_keyboard' => $key]);
                editMessage($from_id, "🔧 یکی از پنل های زیر را برای بخش تست اکانت انتخاب کنید :", $message_id, $key);
            } else {
                alert('❌ هیچ پنلی در ربات ثبت نشده است !');
            }
        } elseif (strpos($data, 'select_test_panel-') !== false) {
            $code = explode('-', $data)[1];
            $panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'");
            if ($panel->num_rows > 0) {
                $sql->query("UPDATE `test_account_setting` SET `panel` = '$code'");
                $panel = $panel->fetch_assoc();
                $manage_test_account = json_encode([
                    'inline_keyboard' => [
                        [['text' => ($test_account_setting['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_test_account_status'], ['text' => '▫️وضعیت :', 'callback_data' => 'null']],
                        [['text' => $panel['name'], 'callback_data' => 'change_test_account_panel'], ['text' => '▫️متصل به پنل :', 'callback_data' => 'null']],
                        [['text' => $sql->query("SELECT * FROM `test_account`")->num_rows, 'callback_data' => 'null'], ['text' => '▫️تعداد اکانت تست :', 'callback_data' => 'null']],
                        [['text' => $test_account_setting['volume'] . ' GB', 'callback_data' => 'change_test_account_volume'], ['text' => '▫️حجم :', 'callback_data' => 'null']],
                        [['text' => $test_account_setting['time'] . ' ساعت', 'callback_data' => 'change_test_account_time'], ['text' => '▫️زمان :', 'callback_data' => 'null']],
                    ]
                ]);
                editMessage($from_id, "✅ عملیات تغییرات با موفقیت انجام شد.\n\n👇🏻 یکی از گزینه های زیر را انتخاب کنید", $message_id, $manage_test_account);
            } else {
                alert('❌ پنل مورد نظر یافت نشد !');
            }
        } elseif ($text == '➕ افزودن سرور') {
            step('add_server_select');
            sendMessage($from_id, "ℹ️ قصد اضافه کردن کدام یک از پنل های زیر را دارید ؟", $select_panel);
        }

        # ------------- hedifay ------------- #
        elseif ($data == 'hedifay') {
            alert('❌ در حال تکمیل کردن این بخش هستیم لطفا صبور باشید !', true);
            exit();
            // step('add_server_hedifay');
            // deleteMessage($from_id, $message_id);
            // sendMessage($from_id, "‌👈🏻⁩ اسم پنل خود را به دلخواه ارسال کنید :↓\n\nمثال نام : 🇳🇱 - هلند\n• این اسم برای کاربران قابل نمایش است.", $cancel_add_server);
        } elseif ($user['step'] == 'add_server_hedifay') {
            if ($sql->query("SELECT `name` FROM `panels` WHERE `name` = '$text'")->num_rows == 0) {
                step('send_address_hedifay');
                file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
                sendMessage($from_id, "🌐 آدرس لاگین به پنل را ارسال کنید.\n\n- example:\n\n<code>https://1.1.1.1.sslip.io/8itQkDU30qCOwzUkK3LnMf58qfsw/175dbb13-95d7-3807-a987-gbs3434bd1b412/admin</code>", $cancel_add_server);
            } else {
                sendMessage($from_id, "❌ پنلی با نام [ <b>$text</b> ] قبلا در ربات ثبت شده !", $cancel_add_server);
            }
        } elseif ($user['step'] == 'send_address_hedifay') {
            if (strlen($text) > 50 and substr($text, -1) != '/') {
                if (checkUrl($text) == 200) {
                    $info = explode("\n", file_get_contents('add_panel.txt'));
                    preg_match('#https:\/\/.*?\/(.*)\/admin#', $text, $matches);
                    $token = $matches[1];
                    $code = rand(111111, 999999);
                    $sql->query("INSERT INTO `hiddify_panels` (`name`, `login_link`, `token`, `code`, `status`, `type`) VALUES ('{$info[0]}', '$text', '$token', '$code', 'active', 'hiddify')");
                    sendMessage($from_id, "✅ پنل هیدیفای  شما با موفقیت به ربات اضافه شد !", $manage_server);
                }
            } else {
                sendMessage($from_id, "❌ آدرس ارسالی شما اشتباه است !", $cancel_add_server);
            }
        }

        # ------------- sanayi ------------- #
        elseif ($data == 'sanayi') {
            step('add_server_sanayi');
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, "‌👈🏻⁩ اسم پنل خود را به دلخواه ارسال کنید :↓\n\nمثال نام : 🇳🇱 - هلند\n• این اسم برای کاربران قابل نمایش است.", $cancel_add_server);
        } elseif ($user['step'] == 'add_server_sanayi') {
            if ($sql->query("SELECT `name` FROM `panels` WHERE `name` = '$text'")->num_rows == 0) {
                step('send_address_sanayi');
                file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
                sendMessage($from_id, "🌐 آدرس لاگین به پنل را ارسال کنید.\n\n- example:\n http://1.1.1.1:8000\n http://1.1.1.1:8000/vrshop\n http://domain.com:8000", $cancel_add_server);
            } else {
                sendMessage($from_id, "❌ پنلی با نام [ <b>$text</b> ] قبلا در ربات ثبت شده !", $cancel_add_server);
            }
        } elseif ($user['step'] == 'send_address_sanayi') {
            if (preg_match("/^(http|https):\/\/(\d+\.\d+\.\d+\.\d+|.*)\:.*$/", $text)) {
                if ($sql->query("SELECT `login_link` FROM `panels` WHERE `login_link` = '$text'")->num_rows == 0) {
                    step('send_username_sanayi');
                    file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
                    sendMessage($from_id, "🔎 - یوزرنیم ( <b>username</b> ) پنل خود را ارسال کنید :", $cancel_add_server);
                } else {
                    sendMessage($from_id, "❌ پنلی با ادرس [ <b>$text</b> ] قبلا در ربات ثبت شده !", $cancel_add_server);
                }
            } else {
                sendMessage($from_id, "🚫 لینک ارسالی شما اشتباه است !", $cancel_add_server);
            }
        } elseif ($user['step'] == 'send_username_sanayi') {
            step('send_password_sanayi');
            file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "🔎 - پسورد ( <b>password</b> ) سرور خود را ارسال کنید :", $cancel_add_server);
        } elseif ($user['step'] == 'send_password_sanayi') {
            step('none');
            $info = explode("\n", file_get_contents('add_panel.txt'));
            $response = loginPanelSanayi($info[1], $info[2], $text);
            if ($response['success']) {
                $code = rand(11111111, 99999999);
                $session = str_replace([" ", "\n", "\t"], ['', '', ''], explode('session	', file_get_contents('cookie.txt'))[1]);
                $sql->query("INSERT INTO `panels` (`name`, `login_link`, `username`, `password`, `token`, `code`, `status`, `type`) VALUES ('{$info[0]}', '{$info[1]}', '{$info[2]}', '$text', '$session', '$code', 'inactive', 'sanayi')");
                $sql->query("INSERT INTO `sanayi_panel_setting` (`code`, `inbound_id`, `example_link`, `flow`) VALUES ('$code', 'none', 'none', 'offflow')");
                sendMessage($from_id, "✅ ربات با موفقیت به پنل شما لاگین شد!\n\n▫️یوزرنیم : <code>{$info[2]}</code>\n▫️پسورد : <code>{$text}</code>\n▫️کد پیگیری : <code>$code</code>", $manage_server);
            } else {
                sendMessage($from_id, "❌ لاگین به پنل با خطا مواجه شد , بعد از گذشت چند دقیقه مجددا تلاش کنید !\n\n🎯 دلایل ممکن متصل نشدن ربات به پنل شما :↓\n\n◽باز نبودن پورت مورد نظر\n◽باز نشدن آدرس ارسالی\n◽آدرس ارسالی اشتباه\n◽یوزرنیم یا پسورد اشتباه\n◽قرار گرفتن آی‌پی در بلاک لیست\n◽️باز نبودن دسترسی CURL\n◽️مشکل کلی هاست", $manage_server);
            }
            foreach (['add_panel.txt', 'cookie.txt'] as $file)
                if (file_exists($file))
                    unlink($file);
        }

        # ------------- marzban ------------- #
        elseif ($data == 'marzban') {
            step('add_server');
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, "‌👈🏻⁩ اسم پنل خود را به دلخواه ارسال کنید :↓\n\nمثال نام : 🇳🇱 - هلند\n• این اسم برای کاربران قابل نمایش است.", $cancel_add_server);
        } elseif ($user['step'] == 'add_server') {
            if ($sql->query("SELECT `name` FROM `panels` WHERE `name` = '$text'")->num_rows == 0) {
                step('send_address');
                file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
                sendMessage($from_id, "🌐 آدرس لاگین به پنل را ارسال کنید.\n\n- example : http://1.1.1.1:8000", $cancel_add_server);
            } else {
                sendMessage($from_id, "❌ پنلی با نام [ <b>$text</b> ] قبلا در ربات ثبت شده !", $cancel_add_server);
            }
        } elseif ($user['step'] == 'send_address') {
            if (preg_match("/^(http|https):\/\/(\d+\.\d+\.\d+\.\d+|.*)\:\d+$/", $text)) {
                if ($sql->query("SELECT `login_link` FROM `panels` WHERE `login_link` = '$text'")->num_rows == 0) {
                    step('send_username');
                    file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
                    sendMessage($from_id, "🔎 - یوزرنیم ( <b>username</b> ) پنل خود را ارسال کنید :", $cancel_add_server);
                } else {
                    sendMessage($from_id, "❌ پنلی با ادرس [ <b>$text</b> ] قبلا در ربات ثبت شده !", $cancel_add_server);
                }
            } else {
                sendMessage($from_id, "🚫 لینک ارسالی شما اشتباه است !", $cancel_add_server);
            }
        } elseif ($user['step'] == 'send_username') {
            step('send_password');
            file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "🔎 - پسورد ( <b>password</b> ) سرور خود را ارسال کنید :", $cancel_add_server);
        } elseif ($user['step'] == 'send_password') {
            step('none');
            $info = explode("\n", file_get_contents('add_panel.txt'));
            $response = loginPanel($info[1], $info[2], $text);
            if (isset($response['access_token'])) {
                $code = rand(11111111, 99999999);
                $sql->query("INSERT INTO `panels` (`name`, `login_link`, `username`, `password`, `token`, `code`, `type`) VALUES ('{$info[0]}', '{$info[1]}', '{$info[2]}', '$text', '{$response['access_token']}', '$code', 'marzban')");
                sendMessage($from_id, "✅ ربات با موفقیت به پنل شما لاگین شد!\n\n▫️یوزرنیم : <code>{$info[2]}</code>\n▫️پسورد : <code>{$text}</code>\n▫️کد پیگیری : <code>$code</code>", $manage_server);
            } else {
                sendMessage($from_id, "❌ لاگین به پنل با خطا مواجه شد , بعد از گذشت چند دقیقه مجددا تلاش کنید !\n\n🎯 دلایل ممکن متصل نشدن ربات به پنل شما :↓\n\n◽باز نبودن پورت مورد نظر\n◽باز نشدن آدرس ارسالی\n◽آدرس ارسالی اشتباه\n◽یوزرنیم یا پسورد اشتباه\n◽قرار گرفتن آی‌پی در بلاک لیست\n◽️باز نبودن دسترسی CURL\n◽️مشکل کلی هاست", $manage_server);
            }
            if (file_exists('add_panel.txt'))
                unlink('add_panel.txt');
        }

        # ------------------------------------ #
        elseif ($text == '🎟 افزودن پلن') {
            step('none');
            sendMessage($from_id, "ℹ️ قصد اضافه کردن چه نوع پلنی را دارید ؟\n\n👇🏻 یکی از گزینه های زیر را انتخاب کنید :", $add_plan_button);
        } elseif ($data == 'add_buy_plan') {
            step('add_name');
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, "👇🏻نام این دسته بندی را  ارسال کنید :↓", $back_panel);
        } elseif ($user['step'] == 'add_name' and $text != $texts['back_to_bot_management_button']) {
            step('add_limit');
            file_put_contents('add_plan.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "👇🏻حجم خود را به صورت عدد صحیح و لاتین ارسال کنید :↓\n\n◽نمونه : <code>50</code>", $back_panel);
        } elseif ($user['step'] == 'add_limit' and $text != $texts['back_to_bot_management_button']) {
            step('add_date');
            file_put_contents('add_plan.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "👇🏻تاریخ خود را به صورت عدد صحیح و لاتین ارسال کنید :↓\n\n◽نمونه : <code>30</code>", $back_panel);
        } elseif ($user['step'] == 'add_date' and $text != $texts['back_to_bot_management_button']) {
            step('add_price');
            file_put_contents('add_plan.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "💸 مبلغ این حجم را به صورت عدد صحیح و لاتین ارسال کنید :↓\n\n◽نمونه : <code>60000</code>", $back_panel);
        } elseif ($user['step'] == 'add_price' and $text != $texts['back_to_bot_management_button']) {
            step('none');
            $info = explode("\n", file_get_contents('add_plan.txt'));
            $code = rand(1111111, 9999999);
            $sql->query("INSERT INTO `category` (`limit`, `date`, `name`, `price`, `code`, `status`) VALUES ('{$info[1]}', '{$info[2]}', '{$info[0]}', '$text', '$code', 'active')");
            sendmessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت و به لیست اضافه شد.\n\n◽حجم ارسالی : <code>{$info[1]}</code>\n◽قیمت ارسالی : <code>$text</code>", $manage_server);
            if (file_exists('add_plan.txt'))
                unlink('add_plan.txt');
        } elseif ($data == 'add_limit_plan') {
            step('add_name_limit');
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, "👇🏻نام این دسته بندی را  ارسال کنید :↓", $back_panel);
        } elseif ($user['step'] == 'add_name_limit' and $text != $texts['back_to_bot_management_button']) {
            step('add_limit_limit');
            file_put_contents('add_plan_limit.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "👇🏻حجم خود را به صورت عدد صحیح و لاتین ارسال کنید :↓\n\n◽نمونه : <code>50</code>", $back_panel);
        } elseif ($user['step'] == 'add_limit_limit' and $text != $texts['back_to_bot_management_button']) {
            step('add_price_limit');
            file_put_contents('add_plan_limit.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "💸 مبلغ این حجم را به صورت عدد صحیح و لاتین ارسال کنید :↓\n\n◽نمونه : <code>60000</code>", $back_panel);
        } elseif ($user['step'] == 'add_price_limit' and $text != $texts['back_to_bot_management_button']) {
            step('none');
            $info = explode("\n", file_get_contents('add_plan_limit.txt'));
            $code = rand(1111111, 9999999);
            $sql->query("INSERT INTO `category_limit` (`limit`, `name`, `price`, `code`, `status`) VALUES ('{$info[1]}', '{$info[0]}', '$text', '$code', 'active')");
            sendmessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت و به لیست اضافه شد.\n\n◽حجم ارسالی : <code>{$info[1]}</code>\n◽قیمت ارسالی : <code>$text</code>", $manage_server);
            if (file_exists('add_plan_limit.txt'))
                unlink('add_plan_limit.txt');
        } elseif ($data == 'add_date_plan') {
            step('add_name_date');
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, "👇🏻نام این دسته بندی را  ارسال کنید :↓", $back_panel);
        } elseif ($user['step'] == 'add_name_date' and $text != $texts['back_to_bot_management_button']) {
            step('add_date_date');
            file_put_contents('add_plan_date.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "👇🏻تاریخ خود را به صورت عدد صحیح و لاتین ارسال کنید :↓\n\n◽نمونه : <code>30</code>", $back_panel);
        } elseif ($user['step'] == 'add_date_date' and $text != $texts['back_to_bot_management_button']) {
            step('add_price_date');
            file_put_contents('add_plan_date.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "💸 مبلغ این حجم را به صورت عدد صحیح و لاتین ارسال کنید :↓\n\n◽نمونه : <code>60000</code>", $back_panel);
        } elseif ($user['step'] == 'add_price_date' and $text != $texts['back_to_bot_management_button']) {
            step('none');
            $info = explode("\n", file_get_contents('add_plan_date.txt'));
            $code = rand(1111111, 9999999);
            $sql->query("INSERT INTO `category_date` (`date`, `name`, `price`, `code`, `status`) VALUES ('{$info[1]}', '{$info[0]}', '$text', '$code', 'active')");
            sendmessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت و به لیست اضافه شد.\n\n◽حجم ارسالی : <code>{$info[1]}</code>\n◽قیمت ارسالی : <code>$text</code>", $manage_server);
            if (file_exists('add_plan_date.txt'))
                unlink('add_plan_date.txt');
        } elseif ($text == '⚙️ لیست سرور ها' or $data == 'back_panellist') {
            step('none');
            $info_servers = $sql->query("SELECT * FROM `panels`");
            if ($info_servers->num_rows == 0) {
                if (!isset($data)) {
                    sendMessage($from_id, "❌ هیچ سروری در ربات ثبت نشده است.");
                } else {
                    editMessage($from_id, "❌ هیچ سروری در ربات ثبت نشده است.", $message_id);
                }
                exit();
            }
            $key[] = [['text' => '▫️وضعیت', 'callback_data' => 'null'], ['text' => '▫️نام', 'callback_data' => 'null'], ['text' => '▫️کد پیگیری', 'callback_data' => 'null']];
            while ($row = $info_servers->fetch_array()) {
                $name = $row['name'];
                $code = $row['code'];
                if ($row['status'] == 'active')
                    $status = '✅ فعال';
                else
                    $status = '❌ غیرفعال';
                $key[] = [['text' => $status, 'callback_data' => 'change_status_panel-' . $code], ['text' => $name, 'callback_data' => 'status_panel-' . $code], ['text' => $code, 'callback_data' => 'status_panel-' . $code]];
            }
            $key[] = [['text' => '❌ بستن پنل | close panel', 'callback_data' => 'close_panel']];
            $key = json_encode(['inline_keyboard' => $key]);
            if (!isset($data)) {
                sendMessage($from_id, "🔎 لیست سرور های ثبت شده شما :\n\n⚙️ با کلیک بر روی کد پیگیری سرور میتوانید وارد بخش مدیریت سرور شوید.\n\nℹ️ برای مدیریت هر کدام بر روی آن کلیک کنید.", $key);
            } else {
                editMessage($from_id, "🔎 لیست سرور های ثبت شده شما :\n\n⚙️ با کلیک بر روی کد پیگیری سرور میتوانید وارد بخش مدیریت سرور شوید.\n\nℹ️ برای مدیریت هر کدام بر روی آن کلیک کنید.", $message_id, $key);
            }
        } elseif (strpos($data, 'change_status_panel-') !== false) {
            $code = explode('-', $data)[1];
            $info_panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'")->fetch_assoc();
            if ($info_panel['type'] == 'sanayi') {
                $sanayi_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$info_panel['code']}'")->fetch_assoc();
                if ($sanayi_setting['example_link'] == 'none') {
                    alert('🔴 برای روشن کردن پنل سنایی ابتدا باید اینباند آیدی و نمونه سرویس را تنظیم کنید !');
                    exit;
                } elseif ($sanayi_setting['inbound_id'] == 'none') {
                    alert('🔴 برای روشن کردن پنل سنایی ابتدا باید اینباند آیدی و نمونه سرویس را تنظیم کنید !');
                    exit;
                }
            }
            $status = $info_panel['status'];
            if ($status == 'active') {
                $sql->query("UPDATE `panels` SET `status` = 'inactive' WHERE `code` = '$code'");
            } else {
                $sql->query("UPDATE `panels` SET `status` = 'active' WHERE `code` = '$code'");
            }
            $key[] = [['text' => '▫️وضعیت', 'callback_data' => 'null'], ['text' => '▫️نام', 'callback_data' => 'null'], ['text' => '▫️کد پیگیری', 'callback_data' => 'null']];
            $result = $sql->query("SELECT * FROM `panels`");
            while ($row = $result->fetch_array()) {
                $name = $row['name'];
                $code = $row['code'];
                if ($row['status'] == 'active')
                    $status = '✅ فعال';
                else
                    $status = '❌ غیرفعال';
                $key[] = [['text' => $status, 'callback_data' => 'change_status_panel-' . $code], ['text' => $name, 'callback_data' => 'status_panel-' . $code], ['text' => $code, 'callback_data' => 'status_panel-' . $code]];
            }
            $key[] = [['text' => '❌ بستن پنل | close panel', 'callback_data' => 'close_panel']];
            $key = json_encode(['inline_keyboard' => $key]);
            editMessage($from_id, "🔎 لیست سرور های ثبت شما :\n\nℹ️ برای مدیریت هر کدام بر روی آن کلیک کنید.", $message_id, $key);
        } elseif (strpos($data, 'status_panel-') !== false or strpos($data, 'update_panel-') !== false) {
            alert('🔄 - لطفا چند ثانیه صبر کنید در حال دریافت اطلاعات . . .', false);

            $code = explode('-', $data)[1];
            $info_server = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'")->fetch_assoc();

            if ($info_server['status'] == 'active')
                $status = '✅ فعال';
            else
                $status = '❌ غیرفعال';
            if (strpos($info_server['login_link'], 'https://') !== false)
                $status_ssl = '✅ فعال';
            else
                $status_ssl = '❌ غیرفعال';

            $info = [
                'ip' => explode(':', str_replace(['http://', 'https://'], '', $info_server['login_link']))[0] ?? '⚠️',
                'port' => explode(':', str_replace(['http://', 'https://'], '', $info_server['login_link']))[1] ?? '⚠️',
                'type' => ($info_server['type'] == 'marzban') ? 'مرزبان' : 'سنایی',
            ];

            $txt = "اطلاعات پنل [ <b>{$info_server['name']}</b> ] با موفقیت دریافت شد.\n\n🔎 وضعیت فعلی در ربات : <b>$status</b>\nℹ️ کد سرور ( برای اطلاعات ) : <code>$code</code>\n\n◽️نوع پنل : <b>{$info['type']}</b>\n◽️لوکیشن : <b>{$info_server['name']}</b>\n◽️آیپی : <code>{$info['ip']}</code>\n◽️پورت : <code>{$info['port']}</code>\n◽️وضعیت ssl : <b>$status_ssl</b>\n\n🔑 یوزرنیم پنل : <code>{$info_server['username']}</code>\n🔑 پسورد پنل : <code>{$info_server['password']}</code>";

            $protocols = explode('|', $info_server['protocols']);
            unset($protocols[count($protocols) - 1]);
            if (in_array('vmess', $protocols))
                $vmess_status = '✅';
            else
                $vmess_status = '❌';
            if (in_array('trojan', $protocols))
                $trojan_status = '✅';
            else
                $trojan_status = '❌';
            if (in_array('vless', $protocols))
                $vless_status = '✅';
            else
                $vless_status = '❌';
            if (in_array('shadowsocks', $protocols))
                $shadowsocks_status = '✅';
            else
                $shadowsocks_status = '❌';

            if ($info_server['type'] == 'marzban') {
                $back_panellist = json_encode([
                    'inline_keyboard' => [
                        [['text' => '🆙 آپدیت اطلاعات', 'callback_data' => 'update_panel-' . $code]],
                        [['text' => '🔎 - Status :', 'callback_data' => 'null'], ['text' => $info_server['status'] == 'active' ? '✅' : '❌', 'callback_data' => 'change_status_panel-' . $code]],
                        [['text' => '🎯 - Flow :', 'callback_data' => 'null'], ['text' => $info_server['flow'] == 'flowon' ? '✅' : '❌', 'callback_data' => 'change_status_flow-' . $code]],
                        [['text' => '🗑 حذف پنل', 'callback_data' => 'delete_panel-' . $code], ['text' => '✍️ تغییر نام', 'callback_data' => 'change_name_panel-' . $code]],
                        [['text' => 'vmess - [' . $vmess_status . ']', 'callback_data' => 'change_protocol|vmess-' . $code], ['text' => 'trojan [' . $trojan_status . ']', 'callback_data' => 'change_protocol|trojan-' . $code], ['text' => 'vless [' . $vless_status . ']', 'callback_data' => 'change_protocol|vless-' . $code]],
                        [['text' => 'shadowsocks [' . $shadowsocks_status . ']', 'callback_data' => 'change_protocol|shadowsocks-' . $code]],
                        [['text' => 'ℹ️ مدیریت اینباند ها', 'callback_data' => 'manage_marzban_inbound-' . $code], ['text' => '⏺ تنظیم اینباند', 'callback_data' => 'set_inbound_marzban-' . $code]],
                        [['text' => '🔙 بازگشت به لیست پنل ها', 'callback_data' => 'back_panellist']],
                    ]
                ]);
            } elseif ($info_server['type'] == 'sanayi') {
                $back_panellist = json_encode([
                    'inline_keyboard' => [
                        [['text' => '🆙 آپدیت اطلاعات', 'callback_data' => 'update_panel-' . $code]],
                        [['text' => '🔎 - Status :', 'callback_data' => 'null'], ['text' => $info_server['status'] == 'active' ? '✅' : '❌', 'callback_data' => 'change_status_panel-' . $code]],
                        [['text' => '🗑 حذف پنل', 'callback_data' => 'delete_panel-' . $code], ['text' => '✍️ تغییر نام', 'callback_data' => 'change_name_panel-' . $code]],
                        [['text' => '🆔 تنظیم اینباند برای ساخت سرویس', 'callback_data' => 'set_inbound_sanayi-' . $code]],
                        [['text' => '🌐 تنظیم نمونه لینک ( سرویس ) برای تحویل', 'callback_data' => 'set_example_link_sanayi-' . $code]],
                        [['text' => 'vmess - [' . $vmess_status . ']', 'callback_data' => 'change_protocol|vmess-' . $code], ['text' => 'trojan [' . $trojan_status . ']', 'callback_data' => 'change_protocol|trojan-' . $code], ['text' => 'vless [' . $vless_status . ']', 'callback_data' => 'change_protocol|vless-' . $code]],
                        [['text' => 'shadowsocks [' . $shadowsocks_status . ']', 'callback_data' => 'change_protocol|shadowsocks-' . $code]],
                        [['text' => '🔙 بازگشت به لیست پنل ها', 'callback_data' => 'back_panellist']],
                    ]
                ]);
            }
            editMessage($from_id, $txt, $message_id, $back_panellist);
        } elseif (strpos($data, 'set_inbound_marzban') !== false) {
            $code = explode('-', $data)[1];
            step('send_inbound_marzban-' . $code);
            sendMessage($from_id, "🆕 نام اینباند مورد نظر خود را ارسال کنید :\n\n❌ توجه داشته باشید که اگر نام اینباند را اشتباه وارد کنید امکان خطا در ساخت سرویس خواهد بود و همچنین اینباند ارسالی شما باید مربوط به پروتکل که برای این پنل در ربات فعال کردید باشد.", $back_panel);
        } elseif (strpos($user['step'], 'send_inbound_marzban') !== false and $text != '✔ اتمام و ثبت') {
            $code = explode('-', $user['step'])[1];
            $rand_code = rand(111111, 999999);
            $panel_fetch = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'")->fetch_assoc();
            $token = loginPanel($panel_fetch['login_link'], $panel_fetch['username'], $panel_fetch['password'])['access_token'];
            $inbounds = inbounds($token, $panel_fetch['login_link']);
            $status = checkInbound(json_encode($inbounds), $text);
            if ($status) {
                $res = $sql->query("INSERT INTO `marzban_inbounds` (`panel`, `inbound`, `code`, `status`) VALUES ('$code', '$text', '$rand_code', 'active')");
                sendMessage($from_id, "✅ اینباند ارسالی شما با موفقیت تنظیم شد.\n\n#️⃣ در صورت ارسال اینباند جدید آن را ارسال کنید و در غیر این صورت دستور /end_inbound را ارسال کنید یا روی دکمه زیر کلیک کنید.", $end_inbound);
            } else {
                sendMessage($from_id, "🔴 اینباند ارسالی شما یافت نشد !", $end_inbound);
            }
        } elseif (($text == '✔ اتمام و ثبت' or $text == '/end_inbound') and strpos($user['step'], 'send_inbound_marzban') !== false) {
            step('none');
            sendMessage($from_id, "✅ همه اینباند های ارسالی شما ثبت شد.", $manage_server);
        } elseif (strpos($data, 'manage_marzban_inbound') !== false) {
            $panel_code = explode('-', $data)[1];
            $fetch_inbounds = $sql->query("SELECT * FROM `marzban_inbounds` WHERE `panel` = '$panel_code'");
            if ($fetch_inbounds->num_rows > 0) {
                while ($row = $fetch_inbounds->fetch_assoc()) {
                    $key[] = [['text' => $row['inbound'], 'callback_data' => 'null'], ['text' => '🗑', 'callback_data' => 'delete_marzban_inbound-' . $row['code'] . '-' . $panel_code]];
                }
                $key[] = [['text' => '🔙 بازگشت', 'callback_data' => 'status_panel-' . $panel_code]];
                $key = json_encode(['inline_keyboard' => $key]);
                editMessage($from_id, "🔎 لیست همه اینباند های ثبت شده برای این پنل نوسط شما به شرح زیر است !", $message_id, $key);
            } else {
                alert('❌ هیچ اینباندی برای این پنل تنظیم نشده است !', true);
            }
        } elseif (strpos($data, 'delete_marzban_inbound') !== false) {
            $panel_code = explode('-', $data)[2];
            $inbound_code = explode('-', $data)[1];
            $fetch = $sql->query("SELECT * FROM `marzban_inbounds` WHERE `panel` = '$panel_code'");
            if ($fetch->num_rows > 0) {
                alert('✅ اینباند انتخابی شما با موفقیت از دیتابیس ربات حذف شد.', true);
                $sql->query("DELETE FROM `marzban_inbounds` WHERE `panel` = '$panel_code' AND `code` = '$inbound_code'");
                $key = json_encode(['inline_keyboard' => [[['text' => '🔎', 'callback_data' => 'manage_marzban_inbound-' . $panel_code]]]]);
                editMessage($from_id, "⬅️ برای بازگشت به لیست اینباند ها , روی دکمه زیر کلیک کنید !", $message_id, $key);
            } else {
                alert('❌ همچین اینباندی در دیتابیس ربات یافت نشد !', true);
            }
        } elseif (strpos($data, 'set_inbound_sanayi') !== false) {
            $code = explode('-', $data)[1];
            step('send_inbound_id-' . $code);
            sendMessage($from_id, "👇 آیدی سرویس مادر که قرار است کلاینت ها داخل آن اد شود را ارسال کنید : ( id ) :", $back_panel);
        } elseif (strpos($user['step'], 'send_inbound_id') !== false) {
            if (is_numeric($text)) {
                $code = explode('-', $user['step'])[1];
                $info_panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'")->fetch_assoc();
                include_once 'api/sanayi.php';
                $xui = new Sanayi($info_panel['login_link'], $info_panel['token']);
                $id_status = json_decode($xui->checkId($text), true)['status'];
                if ($id_status == true) {
                    step('none');
                    if ($sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '$code'")->num_rows > 0) {
                        $sql->query("UPDATE `sanayi_panel_setting` SET `inbound_id` = '$text' WHERE `code` = '$code'");
                    } else {
                        $sql->query("INSERT INTO `sanayi_panel_setting` (`code`, `inbound_id`, `example_link`, `flow`) VALUES ('$code', '$text', 'none', 'offflow')");
                    }
                    sendMessage($from_id, "✅ با موفقیت تنظیم شد !", $manage_server);
                } else {
                    sendMessage($from_id, "❌ اینباندی با ایدی <code>$text</code> پیدا نشد !", $back_panel);
                }
            } else {
                sendMessage($from_id, "❌ مقدار ورودی باید فقط عدد باشد !", $back_panel);
            }
        } elseif (strpos($data, 'set_example_link_sanayi') !== false) {
            $code = explode('-', $data)[1];
            step('set_example_link_sanayi-' . $code);
            sendMessage($from_id, "✏️ نمونه سرویس خود را با توجه به توضیحات زیر ارسال کنید :\n\n▫️به جای جاهای متغیر هر قسمت در لینک سرویس ارسالی مقدار s1 و %s2 و ...% رو جایگزین کنید.\n\nبرای مثال لینک دریافتی :\n\n<code>vless://a8eff4a8-226d3343bbf-9e9d-a35f362c4cb4@1.1.1.1:2053?security=reality&type=grpc&host=&headerType=&serviceName=xyz&sni=cdn.discordapp.com&fp=chrome&pbk=SbVKOEMjK0sIlbwg4akyBg5mL5KZwwB-ed4eEE7YnRc&sid=&spx=#ZZPAnel</code>\n\nو لینک ارسالی شما به ربات باید به شرح زیر باشد ( نمونه ) :\n\n<code>vless://%s1@%s2?security=reality&type=grpc&host=&headerType=&serviceName=xyz&sni=cdn.discordapp.com&fp=chrome&pbk=SbVKOEMjK0sIlbwg4akyBg5mL5KZwwB-ed4eEE7YnRc&sid=&spx=#%s3</code>\n\n⚠️ به صورت صحیح ارسال کنید در غیر این صورت ربات موقع خرید سرویس با خطا مواجه خواهد شد", $back_panel);
        } elseif (strpos($user['step'], 'set_example_link_sanayi') !== false) {
            if (strpos($text, '%s1') !== false and strpos($text, '%s3') !== false) {
                step('none');
                $code = explode('-', $user['step'])[1];
                if ($sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '$code'")->num_rows > 0) {
                    $sql->query("UPDATE `sanayi_panel_setting` SET `example_link` = '$text' WHERE `code` = '$code'");
                } else {
                    $sql->query("INSERT INTO `sanayi_panel_setting` (`code`, `inbound_id`, `example_link`, `flow`) VALUES ('$code', 'none', '$text', 'offflow')");
                }
                sendMessage($from_id, "✅ با موفقیت تنظیم شد !", $manage_server);
            } else {
                sendMessage($from_id, "❌ نمونه لینک ارسالی شما اشتباه است !", $back_panel);
            }
        } elseif (strpos($data, 'change_status_flow-') !== false) {
            $code = explode('-', $data)[1];
            $info_panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'");
            $status = $info_panel->fetch_assoc()['flow'];
            if ($status == 'flowon') {
                $sql->query("UPDATE `panels` SET `flow` = 'flowoff' WHERE `code` = '$code'");
            } else {
                $sql->query("UPDATE `panels` SET `flow` = 'flowon' WHERE `code` = '$code'");
            }
            $back = json_encode(['inline_keyboard' => [[['text' => '🆙 آپدیت اطلاعات', 'callback_data' => 'update_panel-' . $code]]]]);
            editmessage($from_id, '✅ تغییرات با موفقیت انجام شد.', $message_id, $back);
        } elseif (strpos($data, 'change_protocol|') !== false) {
            $code = explode('-', $data)[1];
            $protocol = explode('-', explode('|', $data)[1])[0];
            $panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code' LIMIT 1")->fetch_assoc();
            $protocols = explode('|', $panel['protocols']);
            unset($protocols[count($protocols) - 1]);

            if ($protocol == 'vless') {
                if (in_array($protocol, $protocols)) {
                    unset($protocols[array_search($protocol, $protocols)]);
                } else {
                    array_push($protocols, $protocol);
                }
            } elseif ($protocol == 'vmess') {
                if (in_array($protocol, $protocols)) {
                    unset($protocols[array_search($protocol, $protocols)]);
                } else {
                    array_push($protocols, $protocol);
                }
            } elseif ($protocol == 'trojan') {
                if (in_array($protocol, $protocols)) {
                    unset($protocols[array_search($protocol, $protocols)]);
                } else {
                    array_push($protocols, $protocol);
                }
            } elseif ($protocol == 'shadowsocks') {
                if (in_array($protocol, $protocols)) {
                    unset($protocols[array_search($protocol, $protocols)]);
                } else {
                    array_push($protocols, $protocol);
                }
            }

            $protocols = join('|', $protocols) . '|';
            $sql->query("UPDATE `panels` SET `protocols` = '$protocols' WHERE `code` = '$code' LIMIT 1");

            $back = json_encode(['inline_keyboard' => [[['text' => '🆙 آپدیت اطلاعات', 'callback_data' => 'update_panel-' . $code]]]]);
            editmessage($from_id, '✅ تغییر وضعیت پروتکل با موفقیت انجام شد.', $message_id, $back);
        } elseif (strpos($data, 'change_name_panel-') !== false) {
            $code = explode('-', $data)[1];
            step('change_name-' . $code);
            sendMessage($from_id, "🔰نام جدید پنل را ارسال کنید :", $back_panel);
        } elseif (strpos($user['step'], 'change_name-') !== false) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `panels` SET `name` = '$text' WHERE `code` = '$code'");
            sendMessage($from_id, "✅ نام پنل با موفقیت بر روی [ <b>$text</b> ] تنظیم شد.", $back_panellist);
        } elseif (strpos($data, 'delete_panel-') !== false) {
            step('none');
            $code = explode('-', $data)[1];
            $sql->query("DELETE FROM `panels` WHERE `code` = '$code'");
            $info_servers = $sql->query("SELECT * FROM `panels`");
            if ($info_servers->num_rows == 0) {
                if (!isset($data)) {
                    sendMessage($from_id, "❌ هیچ سروری در ربات ثبت نشده است.");
                } else {
                    editMessage($from_id, "❌ هیچ سروری در ربات ثبت نشده است.", $message_id);
                }
                exit();
            }
            $key[] = [['text' => '▫️وضعیت', 'callback_data' => 'null'], ['text' => '▫️نام', 'callback_data' => 'null'], ['text' => '▫️کد پیگیری', 'callback_data' => 'null']];
            while ($row = $info_servers->fetch_array()) {
                $name = $row['name'];
                $code = $row['code'];
                if ($row['status'] == 'active')
                    $status = '✅ فعال';
                else
                    $status = '❌ غیرفعال';
                $key[] = [['text' => $status, 'callback_data' => 'change_status_panel-' . $code], ['text' => $name, 'callback_data' => 'status_panel-' . $code], ['text' => $code, 'callback_data' => 'status_panel-' . $code]];
            }
            $key[] = [['text' => '❌ بستن پنل | close panel', 'callback_data' => 'close_panel']];
            $key = json_encode(['inline_keyboard' => $key]);
            if (!isset($data)) {
                sendMessage($from_id, "🔎 لیست سرور های ثبت شده شما :\n\n⚙️ با کلیک بر روی کد پیگیری سرور میتوانید وارد بخش مدیریت سرور شوید.\n\nℹ️ برای مدیریت هر کدام بر روی آن کلیک کنید.", $key);
            } else {
                editMessage($from_id, "🔎 لیست سرور های ثبت شده شما :\n\n⚙️ با کلیک بر روی کد پیگیری سرور میتوانید وارد بخش مدیریت سرور شوید.\n\nℹ️ برای مدیریت هر کدام بر روی آن کلیک کنید.", $message_id, $key);
            }
        } elseif ($text == '⚙️ مدیریت پلن ها' or $data == 'back_cat') {
            step('manage_plans');
            if ($text) {
                sendMessage($from_id, "ℹ️ قصد مدیریت کردن کدام پلن را دارید ؟\n\n👇🏻 یکی از گزینه های زیر را انتخاب کنید :", $manage_plans);
            } else {
                editMessage($from_id, "ℹ️ قصد مدیریت کردن کدام پلن را دارید ؟\n\n👇🏻 یکی از گزینه های زیر را انتخاب کنید :", $message_id, $manage_plans);
            }
        } elseif ($data == 'manage_main_plan') {
            step('manage_main_plan');
            $count = $sql->query("SELECT * FROM `category`")->num_rows;
            if ($count == 0) {
                if (isset($data)) {
                    editmessage($from_id, "❌ لیست پلن ها خالی است.", $message_id);
                    exit();
                } else {
                    sendmessage($from_id, "❌ لیست پلن ها خالی است.", $manage_server);
                    exit();
                }
            }
            $result = $sql->query("SELECT * FROM `category`");
            $button[] = [['text' => 'حذف', 'callback_data' => 'null'], ['text' => 'وضعیت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null'], ['text' => 'اطلاعات', 'callback_data' => 'null']];
            while ($row = $result->fetch_array()) {
                $status = $row['status'] == 'active' ? '✅' : '❌';
                $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit-' . $row['code']], ['text' => $status, 'callback_data' => 'change_status_cat-' . $row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list-' . $row['code']], ['text' => '👁', 'callback_data' => 'manage_cat-' . $row['code']]];
            }
            $button = json_encode(['inline_keyboard' => $button]);
            $count = $result->num_rows;
            $count_active = $sql->query("SELECT * FROM `category` WHERE `status` = 'active'")->num_rows;
            if (isset($data)) {
                editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
            } else {
                sendMessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $button);
            }
        } elseif ($data == 'manage_limit_plan') {
            step('manage_limit_plan');
            $count = $sql->query("SELECT * FROM `category_limit`")->num_rows;
            if ($count == 0) {
                if (isset($data)) {
                    editmessage($from_id, "❌ لیست پلن ها خالی است.", $message_id);
                    exit();
                } else {
                    sendmessage($from_id, "❌ لیست پلن ها خالی است.", $manage_server);
                    exit();
                }
            }
            $result = $sql->query("SELECT * FROM `category_limit`");
            $button[] = [['text' => 'حذف', 'callback_data' => 'null'], ['text' => 'وضعیت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null'], ['text' => 'اطلاعات', 'callback_data' => 'null']];
            while ($row = $result->fetch_array()) {
                $status = $row['status'] == 'active' ? '✅' : '❌';
                $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_limit-' . $row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_limit-' . $row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_limit-' . $row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_limit-' . $row['code']]];
            }
            $button = json_encode(['inline_keyboard' => $button]);
            $count = $result->num_rows;
            $count_active = $sql->query("SELECT * FROM `category_limit` WHERE `status` = 'active'")->num_rows;
            if (isset($data)) {
                editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
            } else {
                sendMessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $button);
            }
        } elseif ($data == 'manage_date_plan') {
            step('manage_date_plan');
            $count = $sql->query("SELECT * FROM `category_date`")->num_rows;
            if ($count == 0) {
                if (isset($data)) {
                    editmessage($from_id, "❌ لیست پلن ها خالی است.", $message_id);
                    exit();
                } else {
                    sendmessage($from_id, "❌ لیست پلن ها خالی است.", $manage_server);
                    exit();
                }
            }
            $result = $sql->query("SELECT * FROM `category_date`");
            $button[] = [['text' => 'حذف', 'callback_data' => 'null'], ['text' => 'وضعیت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null'], ['text' => 'اطلاعات', 'callback_data' => 'null']];
            while ($row = $result->fetch_array()) {
                $status = $row['status'] == 'active' ? '✅' : '❌';
                $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_date-' . $row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_date-' . $row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_date-' . $row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_date-' . $row['code']]];
            }
            $button = json_encode(['inline_keyboard' => $button]);
            $count = $result->num_rows;
            $count_active = $sql->query("SELECT * FROM `category_date` WHERE `status` = 'active'")->num_rows;
            if (isset($data)) {
                editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
            } else {
                sendMessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $button);
            }
        } elseif (strpos($data, 'change_status_cat-') !== false) {
            $code = explode('-', $data)[1];
            $info_cat = $sql->query("SELECT * FROM `category` WHERE `code` = '$code' LIMIT 1");
            $status = $info_cat->fetch_assoc()['status'];
            if ($status == 'active') {
                $sql->query("UPDATE `category` SET `status` = 'inactive' WHERE `code` = '$code'");
            } else {
                $sql->query("UPDATE `category` SET `status` = 'active' WHERE `code` = '$code'");
            }
            $button[] = [['text' => 'حذف', 'callback_data' => 'null'], ['text' => 'وضعیت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null'], ['text' => 'اطلاعات', 'callback_data' => 'null']];
            $result = $sql->query("SELECT * FROM `category`");
            while ($row = $result->fetch_array()) {
                $status = $row['status'] == 'active' ? '✅' : '❌';
                $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit-' . $row['code']], ['text' => $status, 'callback_data' => 'change_status_cat-' . $row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list-' . $row['code']], ['text' => '👁', 'callback_data' => 'manage_cat-' . $row['code']]];
            }
            $button = json_encode(['inline_keyboard' => $button]);
            $count = $result->num_rows;
            $count_active = $sql->query("SELECT * FROM `category` WHERE `status` = 'active'")->num_rows;
            if (isset($data)) {
                editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
            } else {
                sendMessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $button);
            }
        } elseif (strpos($data, 'change_status_cat_limit-') !== false) {
            $code = explode('-', $data)[1];
            $info_cat = $sql->query("SELECT * FROM `category_limit` WHERE `code` = '$code' LIMIT 1");
            $status = $info_cat->fetch_assoc()['status'];
            if ($status == 'active') {
                $sql->query("UPDATE `category_limit` SET `status` = 'inactive' WHERE `code` = '$code'");
            } else {
                $sql->query("UPDATE `category_limit` SET `status` = 'active' WHERE `code` = '$code'");
            }
            $button[] = [['text' => 'حذف', 'callback_data' => 'null'], ['text' => 'وضعیت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null'], ['text' => 'اطلاعات', 'callback_data' => 'null']];
            $result = $sql->query("SELECT * FROM `category_limit`");
            while ($row = $result->fetch_array()) {
                $status = $row['status'] == 'active' ? '✅' : '❌';
                $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_limit-' . $row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_limit-' . $row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_limit-' . $row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_limit-' . $row['code']]];
            }
            $button = json_encode(['inline_keyboard' => $button]);
            $count = $result->num_rows;
            $count_active = $sql->query("SELECT * FROM `category_limit` WHERE `status` = 'active'")->num_rows;
            if (isset($data)) {
                editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
            } else {
                sendMessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $button);
            }
        } elseif (strpos($data, 'change_status_cat_date-') !== false) {
            $code = explode('-', $data)[1];
            $info_cat = $sql->query("SELECT * FROM `category_date` WHERE `code` = '$code' LIMIT 1");
            $status = $info_cat->fetch_assoc()['status'];
            if ($status == 'active') {
                $sql->query("UPDATE `category_date` SET `status` = 'inactive' WHERE `code` = '$code'");
            } else {
                $sql->query("UPDATE `category_date` SET `status` = 'active' WHERE `code` = '$code'");
            }
            $button[] = [['text' => 'حذف', 'callback_data' => 'null'], ['text' => 'وضعیت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null'], ['text' => 'اطلاعات', 'callback_data' => 'null']];
            $result = $sql->query("SELECT * FROM `category_date`");
            while ($row = $result->fetch_array()) {
                $status = $row['status'] == 'active' ? '✅' : '❌';
                $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_date-' . $row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_date-' . $row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_date-' . $row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_date-' . $row['code']]];
            }
            $button = json_encode(['inline_keyboard' => $button]);
            $count = $result->num_rows;
            $count_active = $sql->query("SELECT * FROM `category_date` WHERE `status` = 'active'")->num_rows;
            if (isset($data)) {
                editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
            } else {
                sendMessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $button);
            }
        } elseif (strpos($data, 'delete_limit-') !== false) {
            $code = explode('-', $data)[1];
            $sql->query("DELETE FROM `category` WHERE `code` = '$code' LIMIT 1");
            $count = $sql->query("SELECT * FROM `category`")->num_rows;
            if ($count == 0) {
                editmessage($from_id, "❌ لیست پلن ها خالی است.", $message_id);
                exit();
            }
            $result = $sql->query("SELECT * FROM `category`");
            while ($row = $result->fetch_array()) {
                $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit-' . $code], ['text' => $row['name'], 'callback_data' => 'manage_list-' . $row['code']]];
            }
            $button = json_encode(['inline_keyboard' => $button]);
            $count = $result->num_rows;
            editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
        } elseif (strpos($data, 'delete_limit_limit-') !== false) {
            $code = explode('-', $data)[1];
            $sql->query("DELETE FROM `category_limit` WHERE `code` = '$code' LIMIT 1");
            $count = $sql->query("SELECT * FROM `category_limit`")->num_rows;
            if ($count == 0) {
                editmessage($from_id, "❌ لیست پلن ها خالی است.", $message_id);
                exit();
            }
            $result = $sql->query("SELECT * FROM `category_limit`");
            while ($row = $result->fetch_array()) {
                $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_limit-' . $row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_limit-' . $row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_limit-' . $row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_limit-' . $row['code']]];
            }
            $button = json_encode(['inline_keyboard' => $button]);
            $count = $result->num_rows;
            editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
        } elseif (strpos($data, 'delete_limit_date-') !== false) {
            $code = explode('-', $data)[1];
            $sql->query("DELETE FROM `category_date` WHERE `code` = '$code' LIMIT 1");
            $count = $sql->query("SELECT * FROM `category_date`")->num_rows;
            if ($count == 0) {
                editmessage($from_id, "❌ لیست پلن ها خالی است.", $message_id);
                exit();
            }
            $result = $sql->query("SELECT * FROM `category_date`");
            while ($row = $result->fetch_array()) {
                $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_date-' . $row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_date-' . $row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_date-' . $row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_date-' . $row['code']]];
            }
            $button = json_encode(['inline_keyboard' => $button]);
            $count = $result->num_rows;
            editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
        } elseif (strpos($data, 'manage_list-') !== false) {
            $code = explode('-', $data)[1];
            $res = $sql->query("SELECT * FROM `category` WHERE `code` = '$code'")->fetch_assoc();
            alert($res['name']);
        } elseif (strpos($data, 'manage_list_limit-') !== false) {
            $code = explode('-', $data)[1];
            $res = $sql->query("SELECT * FROM `category_limit` WHERE `code` = '$code'")->fetch_assoc();
            alert($res['name']);
        } elseif (strpos($data, 'manage_list_date-') !== false) {
            $code = explode('-', $data)[1];
            $res = $sql->query("SELECT * FROM `category_date` WHERE `code` = '$code'")->fetch_assoc();
            alert($res['name']);
        } elseif (strpos($data, 'manage_cat-') !== false) {
            $code = explode('-', $data)[1];
            $res = $sql->query("SELECT * FROM `category` WHERE `code` = '$code'")->fetch_assoc();
            $key = json_encode([
                'inline_keyboard' => [
                    [['text' => 'تاریخ', 'callback_data' => 'null'], ['text' => 'حجم', 'callback_data' => 'null'], ['text' => 'قیمت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null']],
                    [['text' => $res['date'], 'callback_data' => 'change_date-' . $res['code']], ['text' => $res['limit'], 'callback_data' => 'change_limit-' . $res['code']], ['text' => $res['price'], 'callback_data' => 'change_price-' . $res['code']], ['text' => '✏️', 'callback_data' => 'change_name-' . $res['code']]],
                    [['text' => '⬅️ بازگشت', 'callback_data' => 'back_cat']],
                ]
            ]);
            editmessage($from_id, "🌐 اطلاعات پلن با موفقیت دریافت شد.\n\n▫️نام پلن : <b>{$res['name']}</b>\n▫️حجم : <code>{$res['limit']}</code>\n▫️تاریخ : <code>{$res['date']}</code>\n▫️قیمت : <code>{$res['price']}</code>\n\n📎 با کلیک بر روی هر کدام میتوانید مقدار آن را تغییر دهید !", $message_id, $key);
        } elseif (strpos($data, 'manage_cat_date-') !== false) {
            $code = explode('-', $data)[1];
            $res = $sql->query("SELECT * FROM `category_date` WHERE `code` = '$code'")->fetch_assoc();
            $key = json_encode([
                'inline_keyboard' => [
                    [['text' => 'تاریخ', 'callback_data' => 'null'], ['text' => 'قیمت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null']],
                    [['text' => $res['date'], 'callback_data' => 'change_date_date-' . $res['code']], ['text' => $res['price'], 'callback_data' => 'change_price_date-' . $res['code']], ['text' => '✏️', 'callback_data' => 'change_name_date-' . $res['code']]],
                    [['text' => '⬅️ بازگشت', 'callback_data' => 'back_cat']],
                ]
            ]);
            editmessage($from_id, "🌐 اطلاعات پلن با موفقیت دریافت شد.\n\n▫️نام پلن : <b>{$res['name']}</b>\n▫️تاریخ : <code>{$res['date']}</code>\n▫️قیمت : <code>{$res['price']}</code>\n\n📎 با کلیک بر روی هر کدام میتوانید مقدار آن را تغییر دهید !", $message_id, $key);
        } elseif (strpos($data, 'manage_cat_limit-') !== false) {
            $code = explode('-', $data)[1];
            $res = $sql->query("SELECT * FROM `category_limit` WHERE `code` = '$code'")->fetch_assoc();
            $key = json_encode([
                'inline_keyboard' => [
                    [['text' => 'حجم', 'callback_data' => 'null'], ['text' => 'قیمت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null']],
                    [['text' => $res['limit'], 'callback_data' => 'change_limit_limit-' . $res['code']], ['text' => $res['price'], 'callback_data' => 'change_price_limit-' . $res['code']], ['text' => '✏️', 'callback_data' => 'change_name_limit-' . $res['code']]],
                    [['text' => '⬅️ بازگشت', 'callback_data' => 'back_cat']],
                ]
            ]);
            editmessage($from_id, "🌐 اطلاعات پلن با موفقیت دریافت شد.\n\n▫️نام پلن : <b>{$res['name']}</b>\n▫️حجم : <code>{$res['limit']}</code>\n▫️قیمت : <code>{$res['price']}</code>\n\n📎 با کلیک بر روی هر کدام میتوانید مقدار آن را تغییر دهید !", $message_id, $key);
        } elseif (strpos($data, 'change_date-') !== false) {
            $code = explode('-', $data)[1];
            step('change_date-' . $code);
            sendMessage($from_id, "🔰مقدار جدید را به صورت عدد صحیح و لاتین ارسال کنید :", $back_panel);
        } elseif (strpos($data, 'change_date_date-') !== false) {
            $code = explode('-', $data)[1];
            step('change_date_date-' . $code);
            sendMessage($from_id, "🔰مقدار جدید را به صورت عدد صحیح و لاتین ارسال کنید :", $back_panel);
        } elseif (strpos($data, 'change_limit-') !== false) {
            $code = explode('-', $data)[1];
            step('change_limit-' . $code);
            sendMessage($from_id, "🔰مقدار جدید را به صورت عدد صحیح و لاتین ارسال کنید :", $back_panel);
        } elseif (strpos($data, 'change_limit_limit-') !== false) {
            $code = explode('-', $data)[1];
            step('change_limit_limit-' . $code);
            sendMessage($from_id, "🔰مقدار جدید را به صورت عدد صحیح و لاتین ارسال کنید :", $back_panel);
        } elseif (strpos($data, 'change_price-') !== false) {
            $code = explode('-', $data)[1];
            step('change_price-' . $code);
            sendMessage($from_id, "🔰مقدار جدید را به صورت عدد صحیح و لاتین ارسال کنید :", $back_panel);
        } elseif (strpos($data, 'change_price_date-') !== false) {
            $code = explode('-', $data)[1];
            step('change_price_date-' . $code);
            sendMessage($from_id, "🔰مقدار جدید را به صورت عدد صحیح و لاتین ارسال کنید :", $back_panel);
        } elseif (strpos($data, 'change_price_limit-') !== false) {
            $code = explode('-', $data)[1];
            step('change_price_limit-' . $code);
            sendMessage($from_id, "🔰مقدار جدید را به صورت عدد صحیح و لاتین ارسال کنید :", $back_panel);
        } elseif (strpos($data, 'change_name-') !== false) {
            $code = explode('-', $data)[1];
            step('change_namee-' . $code);
            sendMessage($from_id, "🔰نام جدید را ارسال کنید :", $back_panel);
        } elseif (strpos($data, 'change_name_date-') !== false) {
            $code = explode('-', $data)[1];
            step('change_name_date-' . $code);
            sendMessage($from_id, "🔰نام جدید را ارسال کنید :", $back_panel);
        } elseif (strpos($data, 'change_name_limit-') !== false) {
            $code = explode('-', $data)[1];
            step('change_name_limit-' . $code);
            sendMessage($from_id, "🔰نام جدید را ارسال کنید :", $back_panel);
        } elseif (strpos($user['step'], 'change_date-') !== false and $text != $texts['back_to_bot_management_button']) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `category` SET `date` = '$text' WHERE `code` = '$code' LIMIT 1");
            sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
        } elseif (strpos($user['step'], 'change_date_date-') !== false and $text != $texts['back_to_bot_management_button']) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `category_date` SET `date` = '$text' WHERE `code` = '$code' LIMIT 1");
            sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
        } elseif (strpos($user['step'], 'change_limit-') !== false and $text != $texts['back_to_bot_management_button']) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `category` SET `limit` = '$text' WHERE `code` = '$code' LIMIT 1");
            sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
        } elseif (strpos($user['step'], 'change_limit_limit-') !== false and $text != $texts['back_to_bot_management_button']) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `category_limit` SET `limit` = '$text' WHERE `code` = '$code' LIMIT 1");
            sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
        } elseif (strpos($user['step'], 'change_price-') !== false and $text != $texts['back_to_bot_management_button']) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `category` SET `price` = '$text' WHERE `code` = '$code' LIMIT 1");
            sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
        } elseif (strpos($user['step'], 'change_price_date-') !== false and $text != $texts['back_to_bot_management_button']) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `category_date` SET `price` = '$text' WHERE `code` = '$code' LIMIT 1");
            sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
        } elseif (strpos($user['step'], 'change_price_limit-') !== false and $text != $texts['back_to_bot_management_button']) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `category_limit` SET `price` = '$text' WHERE `code` = '$code' LIMIT 1");
            sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
        } elseif (strpos($user['step'], 'change_namee-') !== false and $text != $texts['back_to_bot_management_button']) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `category` SET `name` = '$text' WHERE `code` = '$code' LIMIT 1");
            sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
        } elseif (strpos($user['step'], 'change_name_date-') !== false and $text != $texts['back_to_bot_management_button']) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `category_date` SET `name` = '$text' WHERE `code` = '$code' LIMIT 1");
            sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
        } elseif (strpos($user['step'], 'change_name_limit-') !== false and $text != $texts['back_to_bot_management_button']) {
            $code = explode('-', $user['step'])[1];
            step('none');
            $sql->query("UPDATE `category_limit` SET `name` = '$text' WHERE `code` = '$code' LIMIT 1");
            sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
        }

        // ----------- manage message ----------- //
        // elseif ($text == '🔎 وضعیت ارسال / فوروارد همگانی') {
        elseif ($text == '🔎 وضعیت ارسال همگانی') {
            $info_send = $sql->query("SELECT * FROM `sends` WHERE `user` = '$from_id'")->fetch_assoc();
            if ($info_send['send'] == 'yes')
                $send_status = '✅';
            else
                $send_status = '❌';
            if ($info_send['step'] == 'send')
                $status_send = '✅';
            else
                $status_send = '❌';
            if ($info_send['step'] == 'forward')
                $status_forward = '✅';
            else
                $status_forward = '❌';
            sendMessage($from_id, "👇🏻وضعیت ارسال های شما به شرح زیر است :\n\nℹ️ در صف ارسال : <b>$send_status</b>\n⬅️ ارسال همگانی : <b>$status_send</b>\n\n🟥 برای لغو ارسال همگانی دستور /cancel_send را ارسال کنید.", $manage_message);
        } elseif ($text == '/cancel_send') {
            // $sql->query("UPDATE `sends` SET `send` = 'no', `text` = 'null', `type` = 'null', `step` = 'null' WHERE `user` = '$from_id'");
            $sql->query("DELETE from `sends`");
            sendMessage($from_id, "✅ ارسال/فوروارد همگانی شما با موفقیت لغو شد.", $manage_message);
        } elseif ($text == '📬 ارسال همگانی') {
            step('send_all');
            sendMessage($from_id, "👇 متن خود را در قالب یک پیام ارسال کنید :", $back_to_manage_message);
        } elseif ($user['step'] == 'send_all') {
            $sql->query("DELETE from `sends`");
            step('none');
            if (isset($update->message->text)) {
                $type = 'text';
            } else {
                $type = $update->message->photo[count($update->message->photo) - 1]->file_id;
                $text = $update->message->caption;
            }
            $sql->query("INSERT INTO sends (`send`,`text`,`type`,`step`,`user`) VALUES ('yes','$text','$type','send',$from_id)");
            // $sql->query("UPDATE `sends` SET `send` = 'yes', `text` = '$text', `type` = '$type', `step` = 'send'");
            // $sql->query("UPDATE sends SET send = 'yes', text = '$text', type = '$type', step = 'send'");
            sendMessage($from_id, "✅ پیام شما با موفقیت به صف ارسال همگانی اضافه شد !", $manage_message);
        } elseif ($text == '📞 ارسال پیام به کاربر' or $text == '📤 ارسال پیام به کاربر') {
            step('sendmessage_user1');
            sendMessage($from_id, "🔢 ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_to_manage_message);
        } elseif ($user['step'] == 'sendmessage_user1' and $text != $texts['back_to_bot_management_button']) {
            if ($sql->query("SELECT `from_id` FROM `users` WHERE `from_id` = '$text'")->num_rows > 0) {
                step('sendmessage_user2');
                file_put_contents('id.txt', $text);
                sendMessage($from_id, "👇 پیام خود را در قالب یک متن ارسال کنید :", $back_to_manage_message);
            } else {
                step('sendmessage_user1');
                sendMessage($from_id, "❌ آیدی عددی ارسالی شما عضو ربات نیست !", $back_to_manage_message);
            }
        } elseif ($user['step'] == 'sendmessage_user2' and $text != $texts['back_to_bot_management_button']) {
            step('none');
            $id = file_get_contents('id.txt');
            sendMessage($from_id, "✅ پیام شما با موفقیت به کاربر <code>$id</code> ارسال شد.", $manage_message);
            if (isset($update->message->text)) {
                sendmessage($id, $text);
            } else {
                $file_id = $update->message->photo[count($update->message->photo) - 1]->file_id;
                $caption = $update->message->caption;
                bot('sendphoto', ['chat_id' => $id, 'photo' => $file_id, 'caption' => $caption]);
            }
            unlink('id.txt');
        } elseif ($text == 'ارسال پیام ها 📧') {
            $info_send = $sql->query("SELECT * FROM `sends` WHERE `user` = '$from_id'")->fetch_assoc();
            if (isset($info_send)) {
                sendmessage($from_id, "درحال ارسال پیام های صف ⌛");
            } else {
                sendmessage($from_id, "صف خالی است ❌");
                exit(0);
            };
            $url = "http://127.0.0.1/ZZPanelBot/send.php";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            // Give the local send worker more time to process (connect=5s, timeout=120s)
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            $response = curl_exec($ch);
            curl_close($ch);
            $sql->query("DELETE from `sends`");
            exit(0);
        }


        // ----------- manage users ----------- //
        elseif ($text == '🔎 اطلاعات کاربر') {
            step('info_user');
            sendMessage($from_id, "🔰ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'info_user') {
            $info = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
            if ($info->num_rows > 0) {
                step('none');
                $info = $info->fetch_assoc();
                $res_get = bot('getchatmember', ['user_id' => $text, 'chat_id' => $text]);
                if ($res_get['ok']) {
                    $first_name = $res_get['result']['user']['first_name'];
                    $username = '@' . $res_get['result']['user']['username'];
                } else {
                    if (!isset($res_get['error_code']) || !isset($res_get['description'])) {
                        throw new Exception(json_encode($res_get, 448));
                    };
                    $username = '⚠ خطا ⚠';
                    $first_name = '⚠ خطا ⚠';
                    // $username = 'خطا: ' . $res_get->error_code;
                    // $first_name = 'خطا: ' . $res_get->description;
                };

                $coin = number_format($info['coin']) ?? 0;
                $count_service = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$text'")->num_rows ?? 0;
                $count_payment = $info['count_charge'] ?? 0;
                $keys = [
                    [
                        [
                            'text' => $texts['services_overview__button'],
                            'callback_data' => 'all_service_count' . '__' . $text
                        ]
                    ]
                ];
                sendMessage($from_id, "⭕️ اطلاعات کاربر [ <code>$text</code> ] با موفقیت دریافت شد.\n\n▫️یوزرنیم کاربر : $username\n▫️نام کاربر : <b>$first_name</b>\n▫️موجودی کاربر : <code>$coin</code> تومان\n\n▫️ تعداد کل سرویس های کاربر : <code>$count_service</code> عدد\n\n▫️تعداد پرداختی کاربر : <code>$count_payment</code> عدد", json_encode(['inline_keyboard' => $keys]));
            } else {
                sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
            }
            // } elseif ($user['step'] == 'info_user') {
            //     $info = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
            //     if ($info->num_rows > 0) {
            //         $info = $info->fetch_assoc();
            //         step('none');
            //         $res_get = bot('getchatmember', ['user_id' => $text, 'chat_id' => $text]);
            //         $first_name = $res_get->result->user->first_name;
            //         $username = '@' . $res_get->result->user->username;
            //         $coin = number_format($info['coin']) ?? 0;
            //         $count_service = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$text'")->num_rows ?? 0;
            //         // $count_service = $info['count_service'] ?? 0;
            //         $count_payment = $info['count_charge'] ?? 0;

            //         $count_all_active = 0;
            //         $count_all_inactive = 0;
            //         $count_all = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$text'")->num_rows;
            //         $services = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$text'");
            //         if ($services->num_rows > 0) {
            //             while ($row = $services->fetch_assoc()) {
            //                 $service_base_name = $row['code'];
            //                 $service_name = $row['code'] . "_" . $text;
            //                 $service_location = $row['location'];
            //                 $mysql_service_panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$service_location'")->fetch_assoc();
            //                 ;
            //                 $marzban_res = getUserInfo($service_name, get_marzban_panel_token($service_location), $mysql_service_panel['login_link']);
            //                 $service_status = $marzban_res['status'];
            //                 if ($service_status == 'active') {
            //                     $count_all_active = $count_all_active + 1;
            //                 } elseif (in_array($service_status, array("disabled", "limited", "expired"))) {
            //                     $count_all_inactive = $count_all_inactive + 1;
            //                 }
            //             }
            //         }

            //         $user_usage = get_users_usage($text);
            //         $total_trafic = $user_usage['total_traffic_bought'];
            //         $used_trafic = $user_usage['total_traffic_used'];


            //         sendMessage($from_id, "⭕️ اطلاعات کاربر [ <code>$text</code> ] با موفقیت دریافت شد.\n\n▫️یوزرنیم کاربر : $username\n▫️نام کاربر : <b>$first_name</b>\n▫️موجودی کاربر : <code>$coin</code> تومان\n\n▫️ تعداد کل سرویس های کاربر : <code>$count_service</code> عدد\n🟢 سرویس های فعال : <code>$count_all_active</code> عدد\n🔴 سرویس های غیرفعال : <code>$count_all_inactive</code> عدد\n\n▫️تعداد پرداختی کاربر : <code>$count_payment</code> عدد\n▫️حجم کل کانفیگ های فعال : <code>$total_trafic</code> GB\n▫️حجم مصرف شده از کانفیگ های فعال : <code>$used_trafic</code> GB", $manage_user);
            //     } else {
            //         sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
            //     }
        } elseif ($user['step'] == 'info_user') {
            $info = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
            if ($info->num_rows > 0) {
                $info = $info->fetch_assoc();
                step('none');
                $res_get = bot('getchatmember', ['user_id' => $text, 'chat_id' => $text]);
                $first_name = $res_get->result->user->first_name;
                $username = '@' . $res_get->result->user->username;
                $coin = number_format($info['coin']) ?? 0;
                $count_service = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$text'")->num_rows ?? 0;
                // $count_service = $info['count_service'] ?? 0;
                $count_payment = $info['count_charge'] ?? 0;

                $count_all_active = 0;
                $count_all_inactive = 0;
                $count_all = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$text'")->num_rows;
                $services = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$text'");
                if ($services->num_rows > 0) {
                    while ($row = $services->fetch_assoc()) {
                        $service_base_name = $row['code'];
                        $service_name = $row['code'] . "_" . $text;
                        $service_location = $row['location'];
                        $mysql_service_panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$service_location'")->fetch_assoc();
                        $getUserInfoRes = getUserInfo($service_name, get_marzban_panel_token($service_location), $mysql_service_panel['login_link']);
                        $marzban_res = $getUserInfoRes[2];
                        $service_status = $marzban_res['status'];
                        if ($service_status == 'active') {
                            $count_all_active = $count_all_active + 1;
                        } elseif (in_array($service_status, array("disabled", "limited", "expired"))) {
                            $count_all_inactive = $count_all_inactive + 1;
                        }
                    }
                }

                $user_usage = get_users_usage($text);
                $total_trafic = $user_usage['total_traffic_bought'];
                $used_trafic = $user_usage['total_traffic_used'];


                sendMessage($from_id, "⭕️ اطلاعات کاربر [ <code>$text</code> ] با موفقیت دریافت شد.\n\n▫️یوزرنیم کاربر : $username\n▫️نام کاربر : <b>$first_name</b>\n▫️موجودی کاربر : <code>$coin</code> تومان\n\n▫️ تعداد کل سرویس های کاربر : <code>$count_service</code> عدد\n🟢 سرویس های فعال : <code>$count_all_active</code> عدد\n🔴 سرویس های غیرفعال : <code>$count_all_inactive</code> عدد\n\n▫️تعداد پرداختی کاربر : <code>$count_payment</code> عدد\n▫️حجم کل کانفیگ های فعال : <code>$total_trafic</code> GB\n▫️حجم مصرف شده از کانفیگ های فعال : <code>$used_trafic</code> GB", $manage_user);
            } else {
                sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
            }
        } elseif ($text == '➕ افزایش موجودی') {
            step('add_coin');
            sendMessage($from_id, "🔰ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'add_coin') {
            $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
            if ($user->num_rows > 0) {
                step('add_coin2');
                file_put_contents('id.txt', $text);
                sendMessage($from_id, "🔎 مقدار مبلغ خود را ارسال کنید :", $back_panel);
            } else {
                sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
            }
        } elseif ($user['step'] == 'add_coin2') {
            step('none');
            $id = file_get_contents('id.txt');
            $sql->query("UPDATE `users` SET `coin` = coin + $text WHERE `from_id` = '$id'");
            sendMessage($from_id, "✅ با موفقیت انجام شد.", $manage_user);
            sendMessage($id, "✅ حساب شما از طرف مدیریت به مقدار <code>$text</code> تومان شارژ شد.");
            unlink('id.txt');
        } elseif ($text == '➖ کسر موجودی') {
            step('rem_coin');
            sendMessage($from_id, "🔰ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'rem_coin' and $text != $texts['back_to_bot_management_button']) {
            $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
            if ($user->num_rows > 0) {
                step('rem_coin2');
                file_put_contents('id.txt', $text);
                sendMessage($from_id, "🔎 مقدار مبلغ خود را ارسال کنید :", $back_panel);
            } else {
                sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
            }
        } elseif ($user['step'] == 'rem_coin2' and $text != $texts['back_to_bot_management_button']) {
            step('none');
            $id = file_get_contents('id.txt');
            $sql->query("UPDATE `users` SET `coin` = coin - $text WHERE `from_id` = '$id'");
            sendMessage($from_id, "✅ با موفقیت انجام شد.", $manage_user);
            sendMessage($id, "✅ از طرف مدیریت مقدار <code>$text</code> تومان از حساب شما کسر شد.");
            unlink('id.txt');
        } elseif (strpos($data, 'cancel_fish') !== false) {
            $id = explode('-', $data)[1];
            editMessage($from_id, "✅ با موفقیت انجام شد !", $message_id);
            sendMessage($id, "❌ فیش ارسالی شما به دلیل اشتباه بودن از طرف مدیریت لغو شد و حساب شما شارژ نشد !");
        } elseif (strpos($data, 'accept_fish') !== false) {
            $id = explode('-', $data)[1];
            $price = explode('-', $data)[2];
            $sql->query("UPDATE `users` SET `coin` = coin + $price WHERE `from_id` = '$id'");
            editMessage($from_id, "✅ با موفقیت انجام شد !", $message_id);
            sendMessage($id, "✅ حساب شما با موفقیت به مبلغ <code>$price</code> تومان شارژ شد !");
        } elseif ($text == '❌ مسدود کردن') {
            step('block');
            sendMessage($from_id, "🔢 ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'block' and $text != $texts['back_to_bot_management_button']) {
            $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
            if ($user->num_rows > 0) {
                step('none');
                $sql->query("UPDATE `users` SET `status` = 'inactive' WHERE `from_id` = '$text'");
                sendMessage($from_id, "✅ کاربر مورد نظر با موفقیت بلاک شد.", $manage_user);
            } else {
                sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
            }
        } elseif ($text == '✅ آزاد کردن') {
            step('unblock');
            sendmessage($from_id, "🔢 ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'unblock' and $text != $texts['back_to_bot_management_button']) {
            $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
            if ($user->num_rows > 0) {
                step('none');
                $sql->query("UPDATE `users` SET `status` = 'active' WHERE `from_id` = '$text'");
                sendMessage($from_id, "✅ کاربر مورد نظر با موفقیت ازاد شد.", $manage_user);
            } else {
                sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
            }
        }

        // ----------- manage setting ----------- //
        elseif ($text == 'غیر فعال یا فعال سازی دکمه شارژ') {
            change_charge_account_button_visibility($from_id);
        } elseif ($text == $texts['change_visibility_account_status_changer_button']) {
            change_account_status_changer_button_visibility($from_id);
        } elseif ($text == '◽بخش ها') {
            sendMessage($from_id, "🔰این بخش تکمیل نشده است !");
        } elseif ($text == '🚫 مدیریت ضد اسپم' or $data == 'back_spam') {
            if (isset($text)) {
                sendMessage($from_id, "🚫 به بخش مدیریت ضد اسپم ربات خوش آمدید!\n\n✏️ با کلیک بر روی هر کدام از دکمه های سمت چپ, میتوانید مقدار فعلی را تغییر دهید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید : ", $manage_spam);
            } else {
                editMessage($from_id, "🚫 به بخش مدیریت ضد اسپم ربات خوش آمدید!\n\n✏️ با کلیک بر روی هر کدام از دکمه های سمت چپ, میتوانید مقدار فعلی را تغییر دهید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید : ", $message_id, $manage_spam);
            }
        } elseif ($data == 'change_status_spam') {
            $status = $sql->query("SELECT * FROM `spam_setting`")->fetch_assoc()['status'];
            if ($status == 'active') {
                $sql->query("UPDATE `spam_setting` SET `status` = 'inactive'");
            } elseif ($status == 'inactive') {
                $sql->query("UPDATE `spam_setting` SET `status` = 'active'");
            }
            $manage_spam = json_encode([
                'inline_keyboard' => [
                    [['text' => ($status == 'active') ? '🔴' : '🟢', 'callback_data' => 'change_status_spam'], ['text' => '▫️وضعیت :', 'callback_data' => 'null']],
                    [['text' => ($spam_setting['status'] == 'ban') ? '🚫 مسدود' : '⚠️ اخطار', 'callback_data' => 'change_type_spam'], ['text' => '▫️مدل برخورد :', 'callback_data' => 'null']],
                    [['text' => $spam_setting['time'] . ' ثانیه', 'callback_data' => 'change_time_spam'], ['text' => '▫️زمان : ', 'callback_data' => 'null']],
                    [['text' => $spam_setting['count_message'] . ' عدد', 'callback_data' => 'change_count_spam'], ['text' => '▫️تعداد پیام : ', 'callback_data' => 'null']],
                ]
            ]);
            editMessage($from_id, "🚫 به بخش مدیریت ضد اسپم ربات خوش آمدید!\n\n✏️ با کلیک بر روی هر کدام از دکمه های سمت چپ, میتوانید مقدار فعلی را تغییر دهید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید : ", $message_id, $manage_spam);
        } elseif ($data == 'change_type_spam') {
            $type = $sql->query("SELECT * FROM `spam_setting`")->fetch_assoc()['type'];
            if ($type == 'ban') {
                $sql->query("UPDATE `spam_setting` SET `type` = 'warn'");
            } elseif ($type == 'warn') {
                $sql->query("UPDATE `spam_setting` SET `type` = 'ban'");
            }
            $manage_spam = json_encode([
                'inline_keyboard' => [
                    [['text' => ($spam_setting['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_spam'], ['text' => '▫️وضعیت :', 'callback_data' => 'null']],
                    [['text' => ($type == 'ban') ? '⚠️ اخطار' : '🚫 مسدود', 'callback_data' => 'change_type_spam'], ['text' => '▫️مدل برخورد :', 'callback_data' => 'null']],
                    [['text' => $spam_setting['time'] . ' ثانیه', 'callback_data' => 'change_time_spam'], ['text' => '▫️زمان : ', 'callback_data' => 'null']],
                    [['text' => $spam_setting['count_message'] . ' عدد', 'callback_data' => 'change_count_spam'], ['text' => '▫️تعداد پیام : ', 'callback_data' => 'null']],
                ]
            ]);
            editMessage($from_id, "🚫 به بخش مدیریت ضد اسپم ربات خوش آمدید!\n\n✏️ با کلیک بر روی هر کدام از دکمه های سمت چپ, میتوانید مقدار فعلی را تغییر دهید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید : ", $message_id, $manage_spam);
        } elseif ($data == 'change_count_spam') {
            step('change_count_spam');
            editMessage($from_id, "🆙 مقدار جدید را به صورت عدد صحیح و درست ارسال کنید :", $message_id, $back_spam);
        } elseif ($user['step'] == 'change_count_spam') {
            if (is_numeric($text)) {
                step('none');
                $sql->query("UPDATE `spam_setting` SET `count_message` = '$text'");
                $manage_spam = json_encode([
                    'inline_keyboard' => [
                        [['text' => ($spam_setting['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_spam'], ['text' => '▫️وضعیت :', 'callback_data' => 'null']],
                        [['text' => ($spam_setting['type'] == 'ban') ? '🚫 مسدود' : '⚠️ اخطار', 'callback_data' => 'change_type_spam'], ['text' => '▫️مدل برخورد :', 'callback_data' => 'null']],
                        [['text' => $spam_setting['time'] . ' ثانیه', 'callback_data' => 'change_time_spam'], ['text' => '▫️زمان : ', 'callback_data' => 'null']],
                        [['text' => $text . ' عدد', 'callback_data' => 'change_count_spam'], ['text' => '▫️تعداد پیام : ', 'callback_data' => 'null']],
                    ]
                ]);
                sendMEssage($from_id, "✅ تغییرات با موفقیت انجام شد !\n🚫 به بخش مدیریت ضد اسپم ربات خوش آمدید!\n\n✏️ با کلیک بر روی هر کدام از دکمه های سمت چپ, میتوانید مقدار فعلی را تغییر دهید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید : ", $manage_spam);
            } else {
                sendMessage($from_id, "❌ عدد ارسالی شما اشتباه است !", $back_spam);
            }
        } elseif ($data == 'change_time_spam') {
            step('change_time_spam');
            editMessage($from_id, "🆙 مقدار جدید را به صورت عدد صحیح و درست ارسال کنید :", $message_id, $back_spam);
        } elseif ($user['step'] == 'change_time_spam') {
            if (is_numeric($text)) {
                step('none');
                $sql->query("UPDATE `spam_setting` SET `time` = '$text'");
                $manage_spam = json_encode([
                    'inline_keyboard' => [
                        [['text' => ($spam_setting['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_spam'], ['text' => '▫️وضعیت :', 'callback_data' => 'null']],
                        [['text' => ($spam_setting['type'] == 'ban') ? '🚫 مسدود' : '⚠️ اخطار', 'callback_data' => 'change_type_spam'], ['text' => '▫️مدل برخورد :', 'callback_data' => 'null']],
                        [['text' => $text . ' ثانیه', 'callback_data' => 'change_time_spam'], ['text' => '▫️زمان : ', 'callback_data' => 'null']],
                        [['text' => $spam_setting['count_message'] . ' عدد', 'callback_data' => 'change_count_spam'], ['text' => '▫️تعداد پیام : ', 'callback_data' => 'null']],
                    ]
                ]);
                sendMEssage($from_id, "✅ تغییرات با موفقیت انجام شد !\n🚫 به بخش مدیریت ضد اسپم ربات خوش آمدید!\n\n✏️ با کلیک بر روی هر کدام از دکمه های سمت چپ, میتوانید مقدار فعلی را تغییر دهید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید : ", $manage_spam);
            } else {
                sendMessage($from_id, "❌ عدد ارسالی شما اشتباه است !", $back_spam);
            }
        } elseif ($text == '◽کانال ها') {
            $lockSQL = $sql->query("SELECT `chat_id`, `name` FROM `lock`");
            if (mysqli_num_rows($lockSQL) > 0) {
                $locksText = "☑️ به بخش (🔒 بخش قفل ها) خوش امدید\n\n🚦 راهنما :\n1 - 👁 برای مشاهده ی هر کدام روی اسم ان بزنید.\n2 - برای حذف هر کدام روی دکمه ی ( 🗑 ) بزنید\n3 - برای افزودن قفل روی دکمه ی ( ➕ افزودن قفل ) بزنید";
                $button[] = [['text' => '🗝 نام قفل', 'callback_data' => 'none'], ['text' => '🗑 حذف', 'callback_data' => 'none']];
                while ($row = $lockSQL->fetch_assoc()) {
                    $name = $row['name'];
                    $link = str_replace("@", "", $row['chat_id']);
                    $button[] = [['text' => $name, 'url' => "https://t.me/$link"], ['text' => '🗑', 'callback_data' => "remove_lock-{$row['chat_id']}"]];
                }
            } else
                $locksText = '❌ شما قفلی برای حذف و مشاهده ندارید لطفا از طریق دکمه ی ( ➕ افزودن قفل ) اضافه کنید.';
            $button[] = [['text' => '➕ افزودن قفل', 'callback_data' => 'addLock']];
            if ($data)
                editmessage($from_id, $locksText, $message_id, json_encode(['inline_keyboard' => $button]));
            else
                sendMessage($from_id, $locksText, json_encode(['inline_keyboard' => $button]));
        } elseif ($data == 'addLock') {
            step('add_channel');
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, "✔ یوزرنیم کانال خود را با @ ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'add_channel' and $data != 'back_look' and $text != $texts['back_to_bot_management_button']) {
            if (strpos($text, "@") !== false) {
                if ($sql->query("SELECT * FROM `lock` WHERE `chat_id` = '$text'")->num_rows == 0) {
                    try {
                        $info_channel = bot('getChatMember', ['chat_id' => $text, 'user_id' => bot('getMe')['result']['id']]);
                    } catch (\Throwable $e) {
                        if (strpos($e->getMessage(), 'member list is inaccessible') !== false) {
                            sendMessage($from_id, "❌  ربات دسترسی به لیست اعضای کانال مربوطه ندارد!", $back_panel);
                            exit(0);
                        } else {
                            throw $e;
                        }
                    }
                    if ($info_channel['result']['status'] == 'administrator') {
                        step('none');
                        $channel_name = bot('getChat', ['chat_id' => $text])['result']['title'] ?? 'بدون نام';
                        $sql->query("INSERT INTO `lock`(`name`, `chat_id`) VALUES ('$channel_name', '$text')");
                        $txt = "✅ کانال شما با موفقیت به لیست جوین اجباری اضافه شد.\n\n🆔 - $text";
                        sendmessage($from_id, $txt, $bot_management_keyboard);
                    } else {
                        sendMessage($from_id, "❌  ربات داخل کانال $text ادمین نیست !", $back_panel);
                    }
                } else {
                    sendMessage($from_id, "❌ این کانال از قبل در ربات ثبت شده است !", $back_panel);
                }
            } else {
                sendmessage($from_id, "❌ یوزرنیم ارسالی شما باید با @ باشد !", $back_panel);
            }
        } elseif (strpos($data, "remove_lock-") !== false) {
            $link = explode("-", $data)[1];
            $sql->query("DELETE FROM `lock` WHERE `chat_id` = '$link' LIMIT 1");
            $lockSQL = $sql->query("SELECT `chat_id`, `name` FROM `lock`");
            if (mysqli_num_rows($lockSQL) > 0) {
                $locksText = "☑️ به بخش (🔒 بخش قفل ها) خوش امدید\n\n🚦 راهنما :\n1 - 👁 برای مشاهده ی هر کدام روی اسم ان بزنید.\n2 - برای حذف هر کدام روی دکمه ی ( 🗑 ) بزنید\n3 - برای افزودن قفل روی دکمه ی ( ➕ افزودن قفل ) بزنید";
                $button[] = [['text' => '🗝 نام قفل', 'callback_data' => 'none'], ['text' => '🗑 حذف', 'callback_data' => 'none']];
                while ($row = $lockSQL->fetch_assoc()) {
                    $name = $row['name'];
                    $link = str_replace("@", "", $row['chat_id']);
                    $button[] = [['text' => $name, 'url' => "https://t.me/$link"], ['text' => '🗑', 'callback_data' => "remove_lock_{$row['chat_id']}"]];
                }
            } else
                $locksText = '❌ شما قفلی برای حذف و مشاهده ندارید لطفا از طریق دکمه ی ( ➕ افزودن قفل ) اضافه کنید.';
            $button[] = [['text' => '➕ افزودن قفل', 'callback_data' => 'addLock']];
            if ($data)
                editmessage($from_id, $locksText, $message_id, json_encode(['inline_keyboard' => $button]));
            else
                sendMessage($from_id, $locksText, json_encode(['inline_keyboard' => $button]));
        }

        // ----------------- manage paymanet ----------------- //
        elseif ($text == '◽تنظیمات درگاه پرداخت') {
            sendMessage($from_id, "⚙️️ به تنظیمات درگاه پرداخت خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :", $manage_payment);
        } elseif ($text == '✏️ وضعیت خاموش/روشن درگاه پرداخت های ربات') {
            sendMessage($from_id, "✏️ وضعیت خاموش/روشن درگاه پرداخت های ربات به شرح زیر است :", $manage_off_on_paymanet);
        } elseif ($data == 'change_status_zarinpal') {
            $status = $sql->query("SELECT * FROM `payment_setting`")->fetch_assoc()['zarinpal_status'];
            if ($status == 'active') {
                $sql->query("UPDATE `payment_setting` SET `zarinpal_status` = 'inactive'");
            } elseif ($status == 'inactive') {
                $sql->query("UPDATE `payment_setting` SET `zarinpal_status` = 'active'");
            }
            $manage_off_on_paymanet = json_encode([
                'inline_keyboard' => [
                    [['text' => ($status == 'inactive') ? '🟢' : '🔴', 'callback_data' => 'change_status_zarinpal'], ['text' => '▫️زرین پال :', 'callback_data' => 'null']],
                    [['text' => ($payment_setting['idpay_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_idpay'], ['text' => '▫️آیدی پی :', 'callback_data' => 'null']],
                    [['text' => ($payment_setting['nowpayment_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_nowpayment'], ['text' => ': nowpayment ▫️', 'callback_data' => 'null']],
                    [['text' => ($payment_setting['card_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_card'], ['text' => '▫️کارت به کارت :', 'callback_data' => 'null']]
                ]
            ]);
            editMessage($from_id, "✏️ وضعیت خاموش/روشن درگاه پرداخت های ربات به شرح زیر است :", $message_id, $manage_off_on_paymanet);
        } elseif ($data == 'change_status_idpay') {
            $status = $sql->query("SELECT * FROM `payment_setting`")->fetch_assoc()['idpay_status'];
            if ($status == 'active') {
                $sql->query("UPDATE `payment_setting` SET `idpay_status` = 'inactive'");
            } elseif ($status == 'inactive') {
                $sql->query("UPDATE `payment_setting` SET `idpay_status` = 'active'");
            }
            $manage_off_on_paymanet = json_encode([
                'inline_keyboard' => [
                    [['text' => ($payment_setting['zarinpal_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_zarinpal'], ['text' => '▫️زرین پال :', 'callback_data' => 'null']],
                    [['text' => ($status == 'inactive') ? '🟢' : '🔴', 'callback_data' => 'change_status_idpay'], ['text' => '▫️آیدی پی :', 'callback_data' => 'null']],
                    [['text' => ($payment_setting['nowpayment_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_nowpayment'], ['text' => ': nowpayment ▫️', 'callback_data' => 'null']],
                    [['text' => ($payment_setting['card_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_card'], ['text' => '▫️کارت به کارت :', 'callback_data' => 'null']]
                ]
            ]);
            editMessage($from_id, "✏️ وضعیت خاموش/روشن درگاه پرداخت های ربات به شرح زیر است :", $message_id, $manage_off_on_paymanet);
        } elseif ($data == 'change_status_nowpayment') {
            $status = $sql->query("SELECT * FROM `payment_setting`")->fetch_assoc()['nowpayment_status'];
            if ($status == 'active') {
                $sql->query("UPDATE `payment_setting` SET `nowpayment_status` = 'inactive'");
            } elseif ($status == 'inactive') {
                $sql->query("UPDATE `payment_setting` SET `nowpayment_status` = 'active'");
            }
            $manage_off_on_paymanet = json_encode([
                'inline_keyboard' => [
                    [['text' => ($payment_setting['zarinpal_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_zarinpal'], ['text' => '▫️زرین پال :', 'callback_data' => 'null']],
                    [['text' => ($payment_setting['idpay_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_idpay'], ['text' => '▫️آیدی پی :', 'callback_data' => 'null']],
                    [['text' => ($status == 'inactive') ? '🟢' : '🔴', 'callback_data' => 'change_status_nowpayment'], ['text' => ': nowpayment ▫️', 'callback_data' => 'null']],
                    [['text' => ($payment_setting['card_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_card'], ['text' => '▫️کارت به کارت :', 'callback_data' => 'null']]
                ]
            ]);
            editMessage($from_id, "✏️ وضعیت خاموش/روشن درگاه پرداخت های ربات به شرح زیر است :", $message_id, $manage_off_on_paymanet);
        } elseif ($data == 'change_status_card') {
            $status = $sql->query("SELECT * FROM `payment_setting`")->fetch_assoc()['card_status'];
            if ($status == 'active') {
                $sql->query("UPDATE `payment_setting` SET `card_status` = 'inactive'");
            } elseif ($status == 'inactive') {
                $sql->query("UPDATE `payment_setting` SET `card_status` = 'active'");
            }
            $manage_off_on_paymanet = json_encode([
                'inline_keyboard' => [
                    [['text' => ($payment_setting['zarinpal_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_zarinpal'], ['text' => '▫️زرین پال :', 'callback_data' => 'null']],
                    [['text' => ($payment_setting['idpay_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_idpay'], ['text' => '▫️آیدی پی :', 'callback_data' => 'null']],
                    [['text' => ($payment_setting['nowpayment_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_nowpayment'], ['text' => ': nowpayment ▫️', 'callback_data' => 'null']],
                    [['text' => ($status == 'inactive') ? '🟢' : '🔴', 'callback_data' => 'change_status_card'], ['text' => '▫️کارت به کارت :', 'callback_data' => 'null']]
                ]
            ]);
            editMessage($from_id, "✏️ وضعیت خاموش/روشن درگاه پرداخت های ربات به شرح زیر است :", $message_id, $manage_off_on_paymanet);
        } elseif ($text == '▫️تنظیم شماره کارت') {
            step('set_card_number');
            sendMessage($from_id, "🪪 لطفا شماره کارت خود را به صورت صحیح و دقیق ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'set_card_number') {
            if (is_numeric($text)) {
                step('none');
                $sql->query("UPDATE `payment_setting` SET `card_number` = '$text'");
                sendMessage($from_id, "✅ شماره کارت ارسالی شما با موفقیت تنظیم شد !\n\n◽️شماره کارت : <code>$text</code>", $manage_payment);
            } else {
                sendMessage($from_id, "❌ شماره کارت ارسالی شما اشتباه است !", $back_panel);
            }
        } elseif ($text == '▫️تنظیم صاحب شماره کارت') {
            step('set_card_number_name');
            sendMessage($from_id, "#️⃣ نام صاحب کارت را به صورت دقیق و صحیح ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'set_card_number_name') {
            step('none');
            $sql->query("UPDATE `payment_setting` SET `card_number_name` = '$text'");
            sendMessage($from_id, "✅ صاحب شماره کارت ارسالی شما با موفقیت تنظیم شد !\n\n◽صاحب ️شماره کارت : <code>$text</code>", $manage_payment);
        } elseif ($text == '◽ NOWPayments') {
            step('set_nowpayment_token');
            sendMessage($from_id, "🔎 لطفا api_key خود را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'set_nowpayment_token') {
            step('none');
            $sql->query("UPDATE `payment_setting` SET `nowpayment_token` = '$text'");
            sendMessage($from_id, "✅ با موفقیت تنظیم شد !", $manage_payment);
        } elseif ($text == '▫️آیدی پی') {
            step('set_idpay_token');
            sendMessage($from_id, "🔎 لطفا api_key آیدی پی خود را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'set_idpay_token') {
            step('none');
            $sql->query("UPDATE `payment_setting` SET `idpay_token` = '$text'");
            sendMessage($from_id, "✅ با موفقیت تنظیم شد !", $manage_payment);
        } elseif ($text == '▫️زرین پال') {
            step('set_zarinpal_token');
            sendMessage($from_id, "🔎 لطفا api_key زرین پال خود را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'set_zarinpal_token') {
            step('none');
            $sql->query("UPDATE `payment_setting` SET `zarinpal_token` = '$text'");
            sendMessage($from_id, "✅ با موفقیت تنظیم شد !", $manage_payment);
        }

        // -----------------manage copens ----------------- //
        elseif ($text == '🎁 مدیریت کد تخفیف' or $data == 'back_copen') {
            step('none');
            if (isset($text)) {
                sendMessage($from_id, "🎁 به بخش مدیریت کد تخفیف ربات خوش آمدید!\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید : ", $manage_copens);
            } else {
                editMessage($from_id, "🎁 به بخش مدیریت کد تخفیف ربات خوش آمدید!\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید : ", $message_id, $manage_copens);
            }
        } elseif ($data == 'add_copen') {
            step('add_copen');
            editMessage($from_id, "🆕 کد تخفیف خود را ارسال کنید :", $message_id, $back_copen);
        } elseif ($user['step'] == 'add_copen') {
            step('send_percent');
            file_put_contents('add_copen.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "🔢 کد تخفیف [ <code>$text</code> ] چند درصد باشد به صورت عدد صحیح ارسال کنید :", $back_copen);
        } elseif ($user['step'] == 'send_percent') {
            if (is_numeric($text)) {
                step('send_count_use');
                file_put_contents('add_copen.txt', "$text\n", FILE_APPEND);
                sendMessage($from_id, "🔢 چند نفر میتوانند از این کد تخفیف استفاده کنند به صورت عدد صحیح ارسال کنید :", $back_copen);
            } else {
                sendMessage($from_id, "❌ عدد ورودی اشتباه است !", $back_copen);
            }
        } elseif ($user['step'] == 'send_count_use') {
            if (is_numeric($text)) {
                step('none');
                $copen = explode("\n", file_get_contents('add_copen.txt'));
                $sql->query("INSERT INTO `copens` (`copen`, `percent`, `count_use`, `status`) VALUES ('{$copen[0]}', '{$copen[1]}', '{$text}', 'active')");
                sendMessage($from_id, "✅ کد تخفیف ارسالی شما با موفقیت اضافه شد !", $back_copen);
                unlink('add_copen.txt');
            } else {
                sendMessage($from_id, "❌ عدد ورودی اشتباه است !", $back_copen);
            }
        } elseif ($data == 'manage_copens') {
            step('manage_copens');
            $copens = $sql->query("SELECT * FROM `copens`");
            if ($copens->num_rows > 0) {
                $key[] = [['text' => '▫️حذف', 'callback_data' => 'null'], ['text' => '▫️وضعیت', 'callback_data' => 'null'], ['text' => '▫️تعداد', 'callback_data' => 'null'], ['text' => '▫️درصد', 'callback_data' => 'null'], ['text' => '▫️کد', 'callback_data' => 'null']];
                while ($row = $copens->fetch_assoc()) {
                    $key[] = [['text' => '🗑', 'callback_data' => 'delete_copen-' . $row['copen']], ['text' => ($row['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_copen-' . $row['copen']], ['text' => $row['count_use'], 'callback_data' => 'change_countuse_copen-' . $row['copen']], ['text' => $row['percent'], 'callback_data' => 'change_percent_copen-' . $row['copen']], ['text' => $row['copen'], 'callback_data' => 'change_code_copen-' . $row['copen']]];
                }
                $key[] = [['text' => '🔙 بازگشت', 'callback_data' => 'back_copen']];
                $key = json_encode(['inline_keyboard' => $key]);
                editMessage($from_id, "✏️ لیست همه ک تخفیف ها به شرح زیر است :\n\n⬅️ با کلیک بر روی هر کدام میتوانید مقدار فعلیشان را تغییر دهید.", $message_id, $key);
            } else {
                alert('❌ هیچ کد تخفیفی در ربات ثبت نشده است !');
            }
        } elseif (strpos($data, 'delete_copen-') !== false) {
            $copen = explode('-', $data)[1];
            alert('🗑 کد تخفیف با موفقیت حذف شد.', false);
            $sql->query("DELETE FROM `copens` WHERE `copen` = '$copen'");
            $copens = $sql->query("SELECT * FROM `copens`");
            if ($copens->num_rows > 0) {
                $key[] = [['text' => '▫️حذف', 'callback_data' => 'null'], ['text' => '▫️وضعیت', 'callback_data' => 'null'], ['text' => '▫️تعداد', 'callback_data' => 'null'], ['text' => '▫️درصد', 'callback_data' => 'null'], ['text' => '▫️کد', 'callback_data' => 'null']];
                while ($row = $copens->fetch_assoc()) {
                    $key[] = [['text' => '🗑', 'callback_data' => 'delete_copen-' . $row['copen']], ['text' => ($row['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_copen-' . $row['copen']], ['text' => $row['count_use'], 'callback_data' => 'change_countuse_copen-' . $row['copen']], ['text' => $row['percent'], 'callback_data' => 'change_percent_copen-' . $row['copen']], ['text' => $row['copen'], 'callback_data' => 'change_code_copen-' . $row['copen']]];
                }
                $key[] = [['text' => '🔙 بازگشت', 'callback_data' => 'back_copen']];
                $key = json_encode(['inline_keyboard' => $key]);
                editMessage($from_id, "✏️ لیست همه ک تخفیف ها به شرح زیر است :\n\n⬅️ با کلیک بر روی هر کدام میتوانید مقدار فعلیشان را تغییر دهید.", $message_id, $key);
            } else {
                editMessage($from_id, "❌ هیچ کد تخفیف دیگری وجود ندارد.", $message_id, $manage_copens);
            }
        } elseif (strpos($data, 'change_status_copen-') !== false) {
            $copen = explode('-', $data)[1];
            $copen_status = $sql->query("SELECT `status` FROM `copens` WHERE `copen` = '$copen'")->fetch_assoc();
            if ($copen_status['status'] == 'active') {
                $sql->query("UPDATE `copens` SET `status` = 'inactive' WHERE `copen` = '$copen'");
            } else {
                $sql->query("UPDATE `copens` SET `status` = 'active' WHERE `copen` = '$copen'");
            }

            $copens = $sql->query("SELECT * FROM `copens`");
            if ($copens->num_rows > 0) {
                $key[] = [['text' => '▫️حذف', 'callback_data' => 'null'], ['text' => '▫️وضعیت', 'callback_data' => 'null'], ['text' => '▫️تعداد', 'callback_data' => 'null'], ['text' => '▫️درصد', 'callback_data' => 'null'], ['text' => '▫️کد', 'callback_data' => 'null']];
                while ($row = $copens->fetch_assoc()) {
                    if ($row['copen'] == $copen) {
                        $status = ($copen_status['status'] == 'active') ? '🔴' : '🟢';
                    } else {
                        $status = ($row['status'] == 'active') ? '🟢' : '🔴';
                    }
                    $key[] = [['text' => '🗑', 'callback_data' => 'delete_copen-' . $row['copen']], ['text' => $status, 'callback_data' => 'change_status_copen-' . $row['copen']], ['text' => $row['count_use'], 'callback_data' => 'change_countuse_copen-' . $row['copen']], ['text' => $row['percent'], 'callback_data' => 'change_percent_copen-' . $row['copen']], ['text' => $row['copen'], 'callback_data' => 'change_code_copen-' . $row['copen']]];
                }
                $key[] = [['text' => '🔙 بازگشت', 'callback_data' => 'back_copen']];
                $key = json_encode(['inline_keyboard' => $key]);
                editMessage($from_id, "✏️ لیست همه ک تخفیف ها به شرح زیر است :\n\n⬅️ با کلیک بر روی هر کدام میتوانید مقدار فعلیشان را تغییر دهید.", $message_id, $key);
            } else {
                editMessage($from_id, "❌ هیچ کد تخفیف دیگری وجود ندارد.", $message_id, $manage_copens);
            }
        } elseif (strpos($data, 'change_countuse_copen-') !== false) {
            $copen = explode('-', $data)[1];
            step('change_countuse_copen-' . $copen);
            editMessage($from_id, "🔢 مقدار جدید را ارسال کنید :", $message_id, $back_copen);
        } elseif (strpos($user['step'], 'change_countuse_copen-') !== false) {
            if (is_numeric($text)) {
                $copen = explode('-', $user['step'])[1];
                $sql->query("UPDATE `copens` SET `count_use` = '$text' WHERE `copen` = '$copen'");
                sendMessage($from_id, "✅ عملیات با موفقیت انجام شد.", $manage_copens);
            } else {
                sendMessage($from_id, "❌ ورودی اشتباه است !", $back_copen);
            }
        } elseif (strpos($data, 'change_percent_copen-') !== false) {
            $copen = explode('-', $data)[1];
            step('change_percent_copen-' . $copen);
            editMessage($from_id, "🔢 مقدار جدید را ارسال کنید :", $message_id, $back_copen);
        } elseif (strpos($user['step'], 'change_percent_copen-') !== false) {
            if (is_numeric($text)) {
                $copen = explode('-', $user['step'])[1];
                $sql->query("UPDATE `copens` SET `percent` = '$text' WHERE `copen` = '$copen'");
                sendMessage($from_id, "✅ عملیات با موفقیت انجام شد.", $manage_copens);
            } else {
                sendMessage($from_id, "❌ ورودی اشتباه است !", $back_copen);
            }
        } elseif (strpos($data, 'change_code_copen-') !== false) {
            $copen = explode('-', $data)[1];
            step('change_code_copen-' . $copen);
            editMessage($from_id, "🔢 مقدار جدید را ارسال کنید :", $message_id, $back_copen);
        } elseif (strpos($user['step'], 'change_code_copen-') !== false) {
            $copen = explode('-', $user['step'])[1];
            $sql->query("UPDATE `copens` SET `copen` = '$text' WHERE `copen` = '$copen'");
            sendMessage($from_id, "✅ عملیات با موفقیت انجام شد.", $manage_copens);
        }

        // -----------------manage texts ----------------- //
        elseif ($text == '◽تنظیم متون ربات') {
            sendMessage($from_id, "⚙️️ به تنظیمات متون ربات خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :", $manage_texts);
        } elseif ($text == '✏️ متن استارت') {
            step('set_start_text');
            sendMessage($from_id, "👇 متن استارت را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'set_start_text') {
            step('none');
            $texts['start'] = str_replace('
        ', '\n', $text);
            file_put_contents($TEXTS_JSON_PATH, json_encode($texts, 448));
            sendMessage($from_id, "✅ متن استارت با موفقیت تنظیم شد !", $manage_texts);
        } elseif ($text == '✏️ متن تعرفه خدمات') {
            step('set_tariff_text');
            sendMessage($from_id, "👇 متن تعرفه خدمات را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'set_tariff_text') {
            step('none');
            $texts['service_tariff'] = str_replace('
        ', '\n', $text);
            file_put_contents($TEXTS_JSON_PATH, json_encode($texts, 448));
            sendMessage($from_id, "✅ متن تعرفه خدمات با موفقیت تنظیم شد !", $manage_text);
        } elseif ($text == '✏️ متن راهنمای اتصال') {
            step('none');
            sendMessage($from_id, "✏️ قصد تنظیم کدوم قسمت راهنمای اتصال را دارید ؟\n\n👇 یکی از گزینه های زیر را انتخاب کنید :", $set_text_edu);
        } elseif (strpos($data, 'set_edu_') !== false) {
            $data_parts = explode('_', $data);
            $platform = end($data_parts);
            step('set_edu_' . $platform);
            sendMessage($from_id, "👇🏻متن مورد نظر خود را به صورت صحیح ارسال کنید :\n\n⬅️ سیستم عامل انتخابی : <b>$platform</b>", $back_panel);
        } elseif (strpos($user['step'], 'set_edu_') !== false) {
            $data_parts = explode('_', $user['step']);
            $platform = end($data_parts);
            step('none');
            $texts['edu_' . $platform] = str_replace('
    ', '\n', $text);
            if (file_put_contents($TEXTS_JSON_PATH, json_encode($texts, 448)) == false) {
                throw new Exception("file_put_contents('{$TEXTS_JSON_PATH}', {$new_texts}) failed");
            }
            sendMessage($from_id, "✅ متن شما با موفقیت تنظیم شد.\n\n#️⃣ سیستم عامل : <b>{$platform}</b>", $manage_texts);
        }

        // -----------------manage admins ----------------- //
        elseif ($text == '➕ افزودن ادمین') {
            step('add_admin');
            sendMessage($from_id, "🔰ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'add_admin' and $text != $texts['back_to_bot_management_button']) {
            $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
            if ($user->num_rows != 0) {
                step('none');
                $sql->query("INSERT INTO `admins` (`chat_id`) VALUES ('$text')");
                sendMessage($from_id, "✅ کاربر <code>$text</code> با موفقیت به لیست ادمین ها اضافه شد.", $manage_admin);
            } else {
                sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
            }
        } elseif ($text == '➖ حذف ادمین') {
            step('rem_admin');
            sendMessage($from_id, "🔰ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
        } elseif ($user['step'] == 'rem_admin' and $text != $texts['back_to_bot_management_button']) {
            $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
            if ($user->num_rows > 0) {
                step('none');
                $sql->query("DELETE FROM `admins` WHERE `chat_id` = '$text'");
                sendMessage($from_id, "✅ کاربر <code>$text</code> با موفقیت از لیست ادمین ها حذف شد.", $manage_admin);
            } else {
                sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
            }
        } elseif ($text == '⚙️ لیست ادمین ها') {
            $res = $sql->query("SELECT * FROM `admins`");
            if ($res->num_rows == 0) {
                sendmessage($from_id, "❌ لیست ادمین های ربات خالی است.");
                exit();
            }
            while ($row = $res->fetch_array()) {
                $key[] = [['text' => $row['chat_id'], 'callback_data' => 'delete_admin-' . $row['chat_id']]];
            }
            $count = $res->num_rows;
            $key = json_encode(['inline_keyboard' => $key]);
            sendMessage($from_id, "🔰لیست ادمین های ربات به شرح زیر است :\n\n🔎 تعداد کل ادمین ها : <code>$count</code>", $key);
        }
    }
} catch (\Throwable $e) {
    // ================= check if it is json if json then decode else send it anyway.
    $recived_error_msg = $e->getMessage();
    $error_msg = json_decode($recived_error_msg);
    if (!isset($error_msg)) {
        $error_msg = $recived_error_msg;
    }
    // ================= Generate debug array
    $report = [
        "APP_VERSION" => $APP_VERSION,
        "USER-DATA" => [
            "chat_id" => $from_id,
            'user_name' => $username,
            'step' => $user['step'],
            'update' => $update,
            'data' => $data,
            'text' => $text,
        ],
        "EXCEPTION-DATA" => [
            'error_calss' => get_class($e),
            'error_msg' => $error_msg,
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'error_trace' => $e->getTrace(),
            'error_trace_string' => $e->getTraceAsString(),
        ]
    ];
    error_log(json_encode($report, 448));
    $init_msg = "☢ FATAL_ERROR ☢\n\n";
    $init_msg .= "⭕ USER_ID: {$from_id}\n";
    $init_msg .= "⭕ USER_NAME: {$username}";
    // ================= Send debug array
    send_debug_msg_to_maintainer($init_msg, $maintainer_telegram_id_number);
    send_debug_data_to_maintainer(json_encode($report, 448), $maintainer_telegram_id_number, TRUE);
    sendMessage($from_id, $texts['error_encounter_msg']);
}
