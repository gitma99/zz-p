<?php
// Name validation helper for service creation/renewal

function validate_service_name($name, $from_id, $location, $sql)
{
    // 1. Length check
    if (strlen($name) > 20) {
        return ['valid' => false, 'error' => 'buy_service__choose_name__error__too_long'];
    }

    // 2. Character set: English letters, numbers, underscore only, no spaces
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        return ['valid' => false, 'error' => 'buy_service_choose_name_hint'];
    }

    // 3. Uniqueness on panel (Marzban)
    $code = $name . '_' . $from_id;
    $panel = get_panel_by_name($location);
    if (!$panel) {
        return ['valid' => false, 'error' => 'server_connection_failed'];
    }
    $token = get_marzban_panel_token($panel['name']);
    if ($token === false) {
        return ['valid' => false, 'error' => 'server_connection_failed'];
    }
    $getUser = getUserInfo($code, $token, $panel['login_link']);
    if ($getUser[0] === false) {
        // Token issue - retry once
        $new_token = get_marzban_panel_token($panel['name']);
        if ($new_token !== false) {
            $panel = get_panel_by_name($location);
            $getUser = getUserInfo($code, get_marzban_panel_token($location), $panel['login_link']);
        } else {
            return ['valid' => false, 'error' => 'server_connection_failed'];
        }
    }

    $repeated_db = $sql->query("SELECT `code` FROM `orders` WHERE `code` = '$name' AND `from_id` = '$from_id'")->num_rows;

    if (isset($getUser[2]) && ($getUser[1] === true || $repeated_db > 0)) {
        return ['valid' => false, 'error' => 'repeated_config_name'];
    }

    if (!isset($getUser[2])) {
        return ['valid' => false, 'error' => 'invalid_config_name'];
    }

    return ['valid' => true];
}