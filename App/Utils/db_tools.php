<?php
// Single home for guarded mysqli prepared statements (PHP 7.4, no deps).
// prepare()/get_result() failures return null/false, never fatal. Statements always closed.
function db_row($sql, $query, $types, $params) {
    $stmt = $sql->prepare($query);
    if (!$stmt) return null;
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $r = $stmt->get_result();
    $row = ($r ? $r->fetch_assoc() : null);
    $stmt->close();
    return $row ? $row : null;
}

function db_exec($sql, $query, $types, $params) {
    $stmt = $sql->prepare($query);
    if (!$stmt) return false;
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? true : false;
}
