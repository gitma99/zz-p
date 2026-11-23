<?php
// Cached-count helper for users.count_service. No top-level side effects; db_tools is loaded by the caller.
function recompute_user_service_count($sql, $from_id)
{
    $row = db_row($sql, "SELECT COUNT(*) AS `n` FROM `orders` WHERE `from_id` = ?", 's', array($from_id));
    if ($row === null) { return false; }
    return db_exec($sql, "UPDATE `users` SET `count_service` = ? WHERE `from_id` = ? LIMIT 1", 'is', array((int)$row['n'], $from_id));
}
