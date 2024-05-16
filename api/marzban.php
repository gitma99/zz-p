<?php
function get_marzban_panel_token($panel_name)
{
    global $sql, $token_tested;
    $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$panel_name'")->fetch_assoc();
    $panel_url = $panel['login_link'];
    $panel_token = $panel['token'];
    if (isset($token_tested)) {
        return $panel_token;
    } else {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $panel_url . "/api/system");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $panel_token, 'Content-Type: application/json'));
        $test_response = curl_exec($ch);
        curl_close($ch);

        $token_test_res = json_decode($test_response, true);
        if (isset($token_test_res['version'])) {
            $token_tested = true;
            return $panel_token;
        } else {
            $panel_username = $panel['username'];
            $panel_password = $panel['password'];
            $new_token = reset_panel_panel_token($panel_name, $panel_url, $panel_username, $panel_password);
            $token_tested = true;
            if ($new_token !== false) {
                return $new_token;
            } else {
                return false;
            }
        }
    }
    // return  $panel;
}


function marzban_renewal_api($username, $new_traffic_limit, $new_expire_time, $token, $url)
{
    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, $url . "/api/user/$username");
    // curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch1, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch1, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch1, CURLOPT_CONNECTTIMEOUT, 1 / 100000); // Set timeout for establishing connection (in seconds)
    curl_setopt($ch1, CURLOPT_TIMEOUT, 1 / 100000); // Set timeout for complete operation (in seconds)
    curl_setopt($ch1, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token, 'Content-Type: application/json'));
    curl_setopt(
        $ch1,
        CURLOPT_POSTFIELDS,
        json_encode(
            array(
                'expire' => $new_expire_time,
                'data_limit' => $new_traffic_limit,
                'data_limit_reset_strategy' => 'no_reset',
                'status' => 'active',
                'username' => $username
            )
        )
    );
    $new_limit_response = curl_exec($ch1);
    curl_close($ch1);

    sleep(1.5);

    $new_limit_response = json_decode($new_limit_response, true);
    if (isset($new_limit_response['username'])) {
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $url . "/api/user/$username/reset");
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token, 'Content-Type: application/json'));
        $reset_data_usage_response = curl_exec($ch2);
        curl_close($ch2);
        return $reset_data_usage_response;
    } else {
        return $new_limit_response;
    }
}


function loginPanel($address, $username, $password)
{
    global $from_id, $cancel_add_server;
    $fields = array('username' => $username, 'password' => $password);
    $curl = curl_init($address . '/api/admin/token');
    $marzban_login_headers = array(
        'Content-Type: application/x-www-form-urlencoded',
        'accept: application/json'

    );
    curl_setopt_array(
        $curl,
        array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => $marzban_login_headers
        )
    );
    $response = curl_exec($curl);
    if ($response === false) {
        sendMessage($from_id, curl_error($curl), $cancel_add_server);
        error_log('cURL Error: ' . curl_error($curl));
        curl_close($curl);
    } else {
        curl_close($curl);
        return json_decode($response, true);
    }
}

function createService($username, $limit, $expire_data, $proxies, $inbounds, $token, $url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '/api/user');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token, 'Content-Type: application/json'));
    if ($inbounds != 'null') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('proxies' => $proxies, 'inbounds' => $inbounds, 'expire' => $expire_data, 'data_limit' => $limit, 'username' => $username, 'data_limit_reset_strategy' => 'no_reset')));
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('proxies' => $proxies, 'expire' => $expire_data, 'data_limit' => $limit, 'username' => $username, 'data_limit_reset_strategy' => 'no_reset')));
    }
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function getUserInfo($sevice_name, $token, $url)
{
    $api_url = $url . '/api/user/' . $sevice_name;
    $req_headers = array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token,

    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (
        isset($response['detail'])
        && in_array($response['detail'], ['Could not validate credentials', 'Not authenticated'])
    ) {
        $token_acccepted = false;
        $user_existed = null;
    } else if (isset($response['username'])) {
        $token_acccepted = true;
        $user_existed = true;
    } else {
        $token_acccepted = true;
        $user_existed = false;
    }


    return [
        $token_acccepted,
        $user_existed,
        $response
    ];
}