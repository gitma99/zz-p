<?php
// LookupCache vocabulary (see GLOSSARY.md): panels/categories re-queryable per request.
require_once __DIR__ . '/db_tools.php';
class RequestCache {
    private $store = array();
    public function has($k) { return array_key_exists($k, $this->store); }
    public function get($k, $d = null) { return isset($this->store[$k]) ? $this->store[$k] : $d; }
    // $ttl accepted-and-ignored: keeps PSR-16 set() shape so a future
    // expiring driver never rewrites call sites. Memory driver has no expiry.
    public function set($k, $v, $ttl = null) { $this->store[$k] = $v; }
    public function delete($k) { unset($this->store[$k]); }
    public function clear() { $this->store = array(); }
    public function toArray() { return $this->store; }
}

$cache = new RequestCache();

function cached_query($key, $fn) {
    global $cache;
    if ($cache->has($key)) return $cache->get($key);
    $val = $fn();
    $cache->set($key, $val);
    return $val;
}
function get_active_rows($key, $table) {
    global $sql;
    return cached_query($key, function () use ($sql, $table) {
        $r = $sql->query("SELECT * FROM `$table` WHERE `status` = 'active'");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : array();
    });
}
function get_active_panels() { return get_active_rows('panels:active', 'panels'); }
function get_active_categories() { return get_active_rows('category:active', 'category'); }
function get_active_date_plans() { return get_active_rows('category_date:active', 'category_date'); }
function get_active_limit_plans() { return get_active_rows('category_limit:active', 'category_limit'); }
function get_panel_by_name($name) {
    global $sql, $cache;
    if ($cache->has('panels:active')) {
        foreach ($cache->get('panels:active') as $row) {
            if ($row['name'] === $name) {
                $cache->set(panel_key($name), $row);
                return $row;
            }
        }
    }
    return cached_query(panel_key($name), function () use ($sql, $name) {
        return db_row($sql, "SELECT * FROM `panels` WHERE `name` = ?", "s", array($name));
    });
}

function panel_key($name) { return 'panel:name:' . $name; }
