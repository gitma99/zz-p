<?php
class RequestCache {
    private $store = array();
    public function has($k) { return array_key_exists($k, $this->store); }
    public function get($k, $d = null) { return isset($this->store[$k]) ? $this->store[$k] : $d; }
    public function set($k, $v) { $this->store[$k] = $v; }
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
function get_active_panels() {
    global $sql;
    return cached_query('panels:active', function () use ($sql) {
        $r = $sql->query("SELECT * FROM `panels` WHERE `status` = 'active'");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : array();
    });
}
function get_active_categories() {
    global $sql;
    return cached_query('category:active', function () use ($sql) {
        $r = $sql->query("SELECT * FROM `category` WHERE `status` = 'active'");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : array();
    });
}
function get_active_date_plans() {
    global $sql;
    return cached_query('category_date:active', function () use ($sql) {
        $r = $sql->query("SELECT * FROM `category_date` WHERE `status` = 'active'");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : array();
    });
}
function get_active_limit_plans() {
    global $sql;
    return cached_query('category_limit:active', function () use ($sql) {
        $r = $sql->query("SELECT * FROM `category_limit` WHERE `status` = 'active'");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : array();
    });
}
function get_panel_by_name($name) {
    global $sql, $cache;
    if ($cache->has('panels:active')) {
        foreach ($cache->get('panels:active') as $row) {
            if ($row['name'] === $name) {
                $cache->set('panel:name:' . $name, $row);
                return $row;
            }
        }
    }
    return cached_query('panel:name:' . $name, function () use ($sql, $name) {
        $r = $sql->query("SELECT * FROM `panels` WHERE `name` = '$name'");
        return $r ? $r->fetch_assoc() : null;
    });
}
