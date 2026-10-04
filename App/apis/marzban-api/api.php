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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
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
    curl_setopt($ch1, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch1, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch1, CURLOPT_CONNECTTIMEOUT, 30); // connect timeout (seconds)
    curl_setopt($ch1, CURLOPT_TIMEOUT, 30); // overall timeout (seconds)
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
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => $marzban_login_headers
        )
    );
    $response = curl_exec($curl);
    if ($response === false) {
        sendMessage($from_id, curl_error($curl), $cancel_add_server);
        Logger::error('Marzban cURL error', ['error' => curl_error($curl)]);
        curl_close($curl);
    } else {
        curl_close($curl);
        return json_decode($response, true);
    }
}

function createService($username, $limit, $expire_data, $proxies, $inbounds, $token, $url)
{
    $post_fields = array(
        'username' => $username, 
        'status' => 'active',
        'expire' => $expire_data, 
        'data_limit' => $limit, 
        'proxies' => $proxies,
        'data_limit_reset_strategy' => 'no_reset'
    );
    if ($inbounds == 'all'){
        $post_fields['inbounds'] = new stdClass();
    } else if ($inbounds != 'null') {
        $post_fields['inbounds'] = $inbounds;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '/api/user');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token, 'Content-Type: application/json'));
    // if ($inbounds != 'null') {
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('proxies' => $proxies, 'inbounds' => $inbounds, 'expire' => $expire_data, 'data_limit' => $limit, 'username' => $username, 'data_limit_reset_strategy' => 'no_reset')));
    // } else {
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('proxies' => $proxies, 'expire' => $expire_data, 'data_limit' => $limit, 'username' => $username, 'data_limit_reset_strategy' => 'no_reset')));
    // }
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_fields));
    $response = curl_exec($ch);
    $paesed_reponse = json_decode($response, true);
    curl_close($ch);
    
    if (isset($paesed_reponse['detail'])) {
        return [
            false, $paesed_reponse['detail']
        ];
    } else {
        return [$paesed_reponse, False];
    }
}

function resetUserDataUsage($username, $token, $url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '/api/user/' . $username . '/reset');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token));
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $response;
}

function getSystemStatus($token, $url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '/api/system');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token));
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $response;
}

function removeuser($username, $token, $url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '/api/user/' . $username);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token));
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $response;
}

function Modifyuser($username, $data, $token, $url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '/api/user/' . $username);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token, 'Content-Type: application/json'));
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $response;
}

function inbounds($token, $url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '/api/inbounds');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $token, 'Content-Type: application/json'));
    $response = json_decode(curl_exec($ch), true);
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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

/**
 * Build a cURL handle for a single /api/users request.
 * Used internally by getUsersInfo for both single and chunked parallel requests.
 */
function _build_users_curl_handle($token, $url, $base_query, $chunk_names)
{
    $req_headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];

    $chunk_user_params = array_map(function ($n) { return 'username=' . urlencode($n); }, $chunk_names);
    $chunk_query = implode('&', $chunk_user_params);
    $full_query = $base_query === '' ? $chunk_query : ($chunk_query === '' ? $base_query : $base_query . '&' . $chunk_query);
    $api_url = $url . '/api/users' . ($full_query === '' ? '' : "?{$full_query}");

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);
    return $ch;
}

function getUsersInfo($token, $url, $list_offset = null, $max_list_count = null, $status = null, $sevice_names = null, $chunk_size = 100)
{
    if (!isset($token) || !is_string($token)) {
        throw new Exception("api.marzban.getUsersInfo: token is invalid", 1);
    }
    if (!isset($url) || !is_string($url)) {
        throw new Exception("api.marzban.getUsersInfo: url is invalid", 1);
    }

    // Build base query params (limit, offset, status) — shared across all chunks
    $params = [];
    if (isset($max_list_count) && $max_list_count !== null) {
        $params['limit'] = intval($max_list_count);
    }
    if (isset($list_offset) && $list_offset !== null) {
        $params['offset'] = intval($list_offset);
    }
    if (isset($status) && $status !== null) {
        $params['status'] = $status;
    }
    $base_query = http_build_query($params);

    $has_names = isset($sevice_names) && is_array($sevice_names) && count($sevice_names) > 0;

    // ── Single request path (no names, or small batch within chunk_size) ──────
    if (!$has_names || count($sevice_names) <= $chunk_size) {
        $single_names = $has_names ? $sevice_names : [];
        $ch = _build_users_curl_handle($token, $url, $base_query, $single_names);
        $response = curl_exec($ch);
        if (is_bool($response)) {
            $err = curl_error($ch);
            curl_close($ch);
            return [
                'success' => false,
                'data'    => null,
                'error'   => "curl_exec returned boolean. cURL error: {$err}"
            ];
        }
        curl_close($ch);
        $decoded = json_decode($response, true);
        return [
            'success' => !isset($decoded['detail']),
            'data'    => isset($decoded['detail']) ? null : $decoded,
            'error'   => isset($decoded['detail']) ? $decoded['detail'] : null
        ];
    }

    // ── Parallel chunked path (curl_multi) ────────────────────────────────────
    // Split names into chunks and fire all requests simultaneously.
    $chunks   = array_chunk($sevice_names, $chunk_size);
    $mh       = curl_multi_init();
    $handles  = [];

    foreach ($chunks as $chunk) {
        $ch = _build_users_curl_handle($token, $url, $base_query, $chunk);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }

    // Execute all handles in parallel
    $running = null;
    do {
        $status_code = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh); // wait for activity to avoid busy-looping
        }
    } while ($running > 0 && $status_code === CURLM_OK);

    // Collect and merge results
    $merged_users = [];
    foreach ($handles as $ch) {
        $raw = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($raw === null || $raw === false) {
            curl_multi_close($mh);
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'One or more chunked requests returned no content.'
            ];
        }

        $decoded = json_decode($raw, true);
        if (isset($decoded['detail'])) {
            curl_multi_close($mh);
            return [
                'success' => false,
                'data'    => null,
                'error'   => $decoded['detail']
            ];
        }

        $merged_users = array_merge($merged_users, $decoded['users'] ?? []);
    }

    curl_multi_close($mh);

    return [
        'success' => true,
        'data'    => ['users' => $merged_users],
        'error'   => null
    ];
}