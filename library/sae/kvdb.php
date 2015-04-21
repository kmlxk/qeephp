<?php

class Q_Sae_Kvdb {

    protected $_kvdb;

    /**
     * 构造函数
     *
     * @param 默认的缓存策略 $default_policy
     */
    function __construct() {
        $this->_kvdb = memcache_init();
        if ($this->_kvdb == false) {
            throw new QCache_Exception('SAE memcache init failed!');
        }
    }

    /**
     * 写入缓存
     *
     * @param string $id
     * @param mixed $data
     * @param array $policy
     */
    function set($id, $data, array $policy = null) {
        $life_time = !isset($policy['life_time']) ? (int) $policy['life_time'] : $this->_default_policy['life_time'];
        $this->_kvdb->set($id, $data, 0, $life_time);
    }

    /**
     * 读取缓存，失败或缓存撒失效时返回 false
     *
     * @param string $id
     *
     * @return mixed
     */
    function get($id) {
        return $this->_kvdb->get($id);
    }

    /**
     * 删除指定的缓存
     *
     * @param string $id
     */
    function remove($id) {
        $this->_kvdb->delete($id);
    }

}
