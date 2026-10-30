<?php
// Single home for guarded mysqli prepared statements (PHP 7.4, no deps).
// Contract violations (param mismatch) throw InvalidArgumentException.
// Runtime/database errors return null/false. Statements always closed.
function db_row($sql, $query, $types = '', $params = []) {
    $expected = substr_count($query, '?');
    $actual = count($params);
    if ($expected !== $actual) {
        throw new InvalidArgumentException("db_row: expected {$expected} param(s), {$actual} given for query: {$query}");
    }
    $stmt = $sql->prepare($query);
    if (!$stmt) return null;
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $r = $stmt->get_result();
    $row = ($r ? $r->fetch_assoc() : null);
    $stmt->close();
    return $row ? $row : null;
}

function db_exec($sql, $query, $types = '', $params = []) {
    $expected = substr_count($query, '?');
    $actual = count($params);
    if ($expected !== $actual) {
        throw new InvalidArgumentException("db_exec: expected {$expected} param(s), {$actual} given for query: {$query}");
    }
    $stmt = $sql->prepare($query);
    if (!$stmt) return false;
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? true : false;
}
