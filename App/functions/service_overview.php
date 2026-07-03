<?php

/**
 * Fetches and aggregates service status stats for a given user from all Marzban panels.
 * Returns an array with counts and traffic totals, or an 'error' key with a message string.
 */
function fetch_user_service_overview($target_user_id, $sql) {
    $count_all_active = 0;
    $count_all_inactive = 0;
    $overall_unknown = 0;
    $total_traffic_bought = 0;
    $total_traffic_used = 0;

    $services_query = $sql->query("SELECT `id`, `code`, `location` FROM `orders` WHERE `from_id` = '" . $sql->real_escape_string($target_user_id) . "'");
    if ($services_query === false) {
        return [
            'error' => '❌ خطا در خواندن سفارش‌ها از پایگاه داده: ' . $sql->error,
            'count_all_active' => 0,
            'count_all_inactive' => 0,
            'overall_unknown' => 0,
            'total_traffic_bought' => 0,
            'total_traffic_used' => 0
        ];
    }
    $service_count = $services_query->num_rows;
    $services = $services_query->fetch_all(MYSQLI_ASSOC) ?: [];

    if ($service_count == 0) {
        return [
            'error' => null,
            'empty' => true,
            'count_all_active' => 0,
            'count_all_inactive' => 0,
            'overall_unknown' => 0,
            'total_traffic_bought' => 0,
            'total_traffic_used' => 0
        ];
    }

    $serviceLocationMap = [];
    $panels_q = $sql->query("SELECT `name`, `login_link` FROM `panels`");
    if ($panels_q === false) {
        return [
            'error' => '❌ خطا در خواندن پنل‌ها از پایگاه داده: ' . $sql->error,
            'count_all_active' => 0,
            'count_all_inactive' => 0,
            'overall_unknown' => 0,
            'total_traffic_bought' => 0,
            'total_traffic_used' => 0
        ];
    }
    foreach ($panels_q->fetch_all(MYSQLI_ASSOC) as $row) {
        $serviceLocationMap[$row['name']] = $row['login_link'];
    }

    $location_sorted_objs = [];
    foreach ($services as $row) {
        $service_base_name = $row['code'];
        $service_name = $service_base_name . '_' . $target_user_id;
        $service_location = $row['location'];
        if (!isset($location_sorted_objs[$service_location])) {
            $location_sorted_objs[$service_location] = [
                'panel_url'        => $serviceLocationMap[$service_location] ?? null,
                'unknown_services' => [],
                'active_services'  => [],
                'inactive_services'=> [],
                'usernames'        => []
            ];
        }
        $location_sorted_objs[$service_location]['unknown_services'][$service_name] = [
            'id' => $row['id'],
            'code' => $service_base_name
        ];
        $location_sorted_objs[$service_location]['usernames'][] = $service_name;
    }

    foreach ($location_sorted_objs as $service_location_name => $location_detail) {
        $panel_url = $location_detail['panel_url'];
        $usernames = $location_detail['usernames'];
        $panel_q = $sql->query("SELECT * FROM `panels` WHERE `name` = '" . $sql->real_escape_string($service_location_name) . "' LIMIT 1");
        if ($panel_q === false) {
            // skip this location if query fails
            continue;
        }
        $panel_info = $panel_q->fetch_assoc();
        if (!$panel_info) {
            continue;
        }
        $token = loginPanel($panel_info['login_link'], $panel_info['username'], $panel_info['password'])['access_token'];
        $panel_res = getUsersInfo($token, $panel_url, null, null, null, $usernames);
        if ($panel_res['success'] == false) {
            return [
                'error' => '❌ خطا در دریافت اطلاعات از پنل: ' . $panel_res['error'],
                'count_all_active' => 0,
                'count_all_inactive' => 0,
                'overall_unknown' => 0,
                'total_traffic_bought' => 0,
                'total_traffic_used' => 0
            ];
        }

        $users_data = $panel_res['data']['users'] ?? [];
        foreach ($users_data as $service) {
            $service_username = $service['username'];
            switch ($service['status']) {
                case 'active':
                    $location_sorted_objs[$service_location_name]['active_services'][$service_username] = true;
                    break;
                case 'disabled':
                case 'limited':
                case 'expired':
                    $location_sorted_objs[$service_location_name]['inactive_services'][$service_username] = true;
                    break;
            }
            unset($location_sorted_objs[$service_location_name]['unknown_services'][$service_username]);
            $total_traffic_bought += $service['data_limit'];
            $total_traffic_used   += $service['used_traffic'];
        }
        $count_all_active   += count($location_sorted_objs[$service_location_name]['active_services']);
        $count_all_inactive += count($location_sorted_objs[$service_location_name]['inactive_services']);
        $overall_unknown    += count($location_sorted_objs[$service_location_name]['unknown_services']);
    }
    
        # ==== Archive then delete unknown services safely ==== #
        # For safety we archive the full row into `orders_archive` and then delete by `id` inside a transaction.
        foreach ($location_sorted_objs as $service_location_name => $location_detail) {
            foreach ($location_detail['unknown_services'] as $service_username => $svc) {
                $order_id = (int)($svc['id'] ?? 0);
                if ($order_id <= 0) {
                    return [
                        'error' => '❌ شناسه سفارش نامعتبر برای سرویس ناشناخته: ' . $service_username,
                        'count_all_active' => $count_all_active,
                        'count_all_inactive' => $count_all_inactive,
                        'overall_unknown' => $overall_unknown,
                        'total_traffic_bought' => $total_traffic_bought,
                        'total_traffic_used' => $total_traffic_used
                    ];
                }

                // ensure archive table exists (create as copy of structure)
                $sql->query("CREATE TABLE IF NOT EXISTS `orders_archive` LIKE `orders`");

                // perform archive + delete inside transaction
                $sql->begin_transaction();
                // insert the full row into archive
                $archive_stmt = $sql->prepare("INSERT INTO `orders_archive` SELECT * FROM `orders` WHERE `id` = ?");
                if (!$archive_stmt) {
                    $sql->rollback();
                    return [
                        'error' => '❌ خطا در آماده‌سازی آرشیو سفارش: ' . $sql->error,
                        'count_all_active' => $count_all_active,
                        'count_all_inactive' => $count_all_inactive,
                        'overall_unknown' => $overall_unknown,
                        'total_traffic_bought' => $total_traffic_bought,
                        'total_traffic_used' => $total_traffic_used
                    ];
                }
                $archive_stmt->bind_param('i', $order_id);
                $archive_ok = $archive_stmt->execute();
                $archive_stmt->close();
                if ($archive_ok === false) {
                    $sql->rollback();
                    return [
                        'error' => '❌ خطا در آرشیو سفارش: ' . $sql->error,
                        'count_all_active' => $count_all_active,
                        'count_all_inactive' => $count_all_inactive,
                        'overall_unknown' => $overall_unknown,
                        'total_traffic_bought' => $total_traffic_bought,
                        'total_traffic_used' => $total_traffic_used
                    ];
                }

                // delete by id to avoid removing other rows with same code
                $del_stmt = $sql->prepare("DELETE FROM `orders` WHERE `id` = ? AND `from_id` = ? LIMIT 1");
                if (!$del_stmt) {
                    $sql->rollback();
                    return [
                        'error' => '❌ خطا در آماده‌سازی حذف سفارش: ' . $sql->error,
                        'count_all_active' => $count_all_active,
                        'count_all_inactive' => $count_all_inactive,
                        'overall_unknown' => $overall_unknown,
                        'total_traffic_bought' => $total_traffic_bought,
                        'total_traffic_used' => $total_traffic_used
                    ];
                }
                $del_stmt->bind_param('ii', $order_id, $target_user_id);
                $del_ok = $del_stmt->execute();
                $affected = $del_stmt->affected_rows;
                $del_stmt->close();

                if ($del_ok === false || $affected !== 1) {
                    $sql->rollback();
                    return [
                        'error' => '❌ خطا در حذف سرویس ناشناخته: ' . $service_username . ' (deleted rows: ' . intval($affected) . ')',
                        'count_all_active' => $count_all_active,
                        'count_all_inactive' => $count_all_inactive,
                        'overall_unknown' => $overall_unknown,
                        'total_traffic_bought' => $total_traffic_bought,
                        'total_traffic_used' => $total_traffic_used
                    ];
                }

                $sql->commit();
            }
        }

        // $user_usage = get_users_usage($target_user_id);

    return [
        'error'               => null,
        'empty'               => false,
        'count_all_active'    => $count_all_active,
        'count_all_inactive'  => $count_all_inactive,
        'overall_unknown'     => $overall_unknown,
        'total_traffic_bought'=> $total_traffic_bought,
        'total_traffic_used'  => $total_traffic_used
    ];
}
