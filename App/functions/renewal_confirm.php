<?php
// Renewal delivery. Order: validate state → order/panel/plan → token → panel PUT → persist (transaction) → cleanup → message. No top-level side effects; resolves all helpers at call time so tests can stub them.

function renewal_confirm($from_id)
{
    global $sql, $texts, $my_texts, $start_key, $user;

    try {
        // 1. Leave the wizard, tell the user we are working.
        step('none');
        sendMessage($from_id, $texts['create_service_proccess']);

        // 2. FlowState must be complete and the price usable.
        $state = read_renewal_json($from_id);
        $missing = renewal_state_missing($state);
        if ($missing !== array()) {
            Logger::warning('renewal_state_missing', array('from_id' => $from_id, 'missing' => $missing));
            sendMessage($from_id, $texts['renewal_service_config_not_found'], $start_key);
            return;
        }
        $code = $state['config_name'];
        $code_base = $state['config_base_name'];
        $plan = $state['new_plan'];
        $price = $state['new_plan_price'];
        $location = $state['new_server'];
        if (!is_numeric($price) || $price < 0) {
            Logger::error('renewal_bad_price', array('from_id' => $from_id, 'code' => $code_base, 'price' => $price));
            sendMessage($from_id, sprintf($texts['renew_error'], 1), $start_key);
            return;
        }

        // 3. The order exists, is marzban, and is on the panel the user chose.
        $order = db_row($sql, "SELECT * FROM `orders` WHERE `code` = ? AND `from_id` = ? LIMIT 1", "ss", array($code_base, $from_id));
        if (!$order) {
            Logger::error('renewal_order_missing', array('from_id' => $from_id, 'code' => $code_base));
            sendMessage($from_id, sprintf($texts['renew_error'], 2), $start_key);
            return;
        }
        if ($order['type'] !== 'marzban') {
            Logger::error('renewal_not_marzban', array('from_id' => $from_id, 'code' => $code_base, 'type' => $order['type']));
            sendMessage($from_id, sprintf($texts['renew_error'], 2), $start_key);
            return;
        }
        if ($location !== $order['location']) {
            Logger::error('renewal_panel_mismatch', array('from_id' => $from_id, 'code' => $code_base, 'state' => $location, 'order' => $order['location']));
            sendMessage($from_id, sprintf($texts['renew_error'], 2), $start_key);
            return;
        }

        // 4. Plan and panel must exist and be marzban.
        $plan_row = db_row($sql, "SELECT * FROM `category` WHERE `name` = ? LIMIT 1", "s", array($plan));
        if (!$plan_row) {
            Logger::error('renewal_plan_missing', array('from_id' => $from_id, 'code' => $code_base, 'plan' => $plan));
            sendMessage($from_id, sprintf($texts['renew_error'], 0), $start_key);
            return;
        }
        $limit = $plan_row['limit'];
        $date = $plan_row['date'];
        $panel = db_row($sql, "SELECT * FROM `panels` WHERE `name` = ? LIMIT 1", "s", array($location));
        if (!$panel) {
            Logger::error('renewal_panel_missing', array('from_id' => $from_id, 'code' => $code_base, 'location' => $location));
            sendMessage($from_id, sprintf($texts['renew_error'], 2), $start_key);
            return;
        }
        if ($panel['type'] !== 'marzban') {
            // Existing copy, kept verbatim: non-marzban panels have no renewal path.
            sendMessage($from_id, "Not implanted Error", $start_key);
            return;
        }

        // 5. The user must afford it and the panel token must work.
        if ($user['coin'] < $price) {
            sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
            return;
        }
        $token = get_marzban_panel_token($panel['name']);
        if ($token === false) {
            Logger::error('renewal_token_failed', array('from_id' => $from_id, 'code' => $code_base, 'location' => $location));
            sendMessage($from_id, $my_texts['error_show_service__token_is_incorrect'], $start_key);
            return;
        }

        // 6. The PUT is the source of truth: accepted means renewed.
        $res = marzban_renewal_api($code, convertToBytes($limit . 'GB'), strtotime("+ $date day"), $token, $panel['login_link']);
        if (empty($res['ok'])) {
            Logger::error('renewal_api_failed', array('from_id' => $from_id, 'code' => $code_base, 'location' => $location, 'error' => isset($res['error']) ? $res['error'] : null));
            sendMessage($from_id, sprintf($texts['renew_error'], 1), $start_key);
            return;
        }
        // Accepted body missing links/subscription_url: still renewed. Charge and
        // persist; deliver without the sub URL (owner decision 2026-11-10).
        $body = is_array($res['body']) ? $res['body'] : array();
        if (!isset($body['links']) || !is_array($body['links']) || !isset($body['subscription_url'])) {
            Logger::warning('renewal_api_incomplete', array('from_id' => $from_id, 'code' => $code_base, 'location' => $location));
        }
        $links = renewal_links_text($body);
        $subscribe = renewal_subscribe_url(isset($body['subscription_url']) ? $body['subscription_url'] : '', $panel['login_link']);

        // 7. Usage reset is best-effort: it cannot undo the paid renewal.
        $reset = marzban_reset_user_usage($code, $token, $panel['login_link']);
        if (empty($reset['ok'])) {
            Logger::warning('renewal_reset_failed', array('from_id' => $from_id, 'code' => $code_base, 'location' => $location, 'error' => isset($reset['error']) ? $reset['error'] : null));
        }

        // 8. Persist before deliver: row lock, order update, coin charge.
        try {
            $sql->begin_transaction();
            $balance_row = db_row($sql, "SELECT `coin` FROM `users` WHERE `from_id` = ? LIMIT 1 FOR UPDATE", "s", array($from_id));
            if (!$balance_row || !isset($balance_row['coin']) || $balance_row['coin'] < $price) {
                throw new RuntimeException('renewal_confirm: insufficient balance under lock');
            }
            if (!db_exec($sql, "UPDATE `orders` SET `location` = ?, `date` = ?, `volume` = ?, `link` = ?, `price` = ? WHERE `code` = ? AND `from_id` = ?", "sssssss", array($location, $date, $limit, $links, $price, $code_base, $from_id))) {
                throw new RuntimeException('renewal_confirm: orders UPDATE failed');
            }
            if (!db_exec($sql, "UPDATE `users` SET `coin` = coin - ? WHERE `from_id` = ? LIMIT 1", "ss", array($price, $from_id))) {
                throw new RuntimeException('renewal_confirm: coin UPDATE failed');
            }
            $sql->commit();
        } catch (\Throwable $e) {
            $sql->rollback();
            Logger::error('renewal_persist_failed', array('from_id' => $from_id, 'code' => $code_base, 'location' => $location, 'price' => $price, 'error' => $e->getMessage()));
            sendMessage($from_id, sprintf($texts['renew_persist_failed'], $code_base), $start_key);
            return;
        }

        // 9. The renewal is recorded; clear the wizard state.
        delete_temp_file('renewal-service-' . $from_id . '.json');
        Logger::info('renewal_completed', array('from_id' => $from_id, 'code' => $code_base, 'location' => $location, 'plan' => $plan, 'price' => $price));

        // 10. Deliver. Delivery failure is logged; the money path already held.
        $bot_username = '*';
        try {
            $me = getMe();
            if (is_array($me) && isset($me['result']['username'])) {
                $bot_username = $me['result']['username'];
            }
        } catch (\Throwable $e) {
            Logger::warning('renewal_getme_failed', array('from_id' => $from_id, 'error' => $e->getMessage()));
        }
        $key = isset($texts['success_renew_service']) ? 'success_renew_service' : 'success_create_service';
        $caption_text = sprintf($texts[$key], $code, $location, $date, $limit, number_format($price), '', '@' . $bot_username);
        $message_text = sprintf($texts[$key], $code_base, $location, $date, $limit, number_format($price), $subscribe, '@' . $bot_username);
        try {
            sendSuccessResponse($from_id, $panel['qr_code'], $subscribe, $caption_text, $message_text, $start_key);
        } catch (\Throwable $e) {
            Logger::error('renewal_delivery_failed', array('from_id' => $from_id, 'code' => $code_base, 'location' => $location, 'error' => $e->getMessage()));
            sendMessage($from_id, $texts['qr_unavailable_hint'], $start_key);
        }
    } catch (\Throwable $e) {
        // Last line of defense: never let the money path throw out.
        Logger::error('renewal_confirm_unhandled', array('from_id' => $from_id, 'error' => $e->getMessage()));
        sendMessage($from_id, sprintf($texts['renew_error'], 1), $start_key);
    }
}

function renewal_state_missing($data = null)
{
    $keys = array('config_name', 'config_base_name', 'new_plan', 'new_plan_price', 'new_server');
    $missing = array();
    if (!is_array($data)) {
        return $keys;
    }
    foreach ($keys as $k) {
        if (!isset($data[$k]) || $data[$k] === '') {
            $missing[] = $k;
        }
    }
    return $missing;
}

function renewal_links_text($body)
{
    if (!is_array($body) || !isset($body['links']) || !is_array($body['links'])) {
        return '';
    }
    return implode("\n\n", $body['links']);
}

function renewal_subscribe_url($sub_url, $panel_base)
{
    if (!is_string($sub_url) || $sub_url === '') {
        return '';
    }
    if (strpos($sub_url, 'http') === 0) {
        return $sub_url;
    }
    return $panel_base . $sub_url;
}
