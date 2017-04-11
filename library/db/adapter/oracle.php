<?php

// $Id: mysql.php 2403 2009-04-07 03:52:48Z dualface $

/**
 * 定义 QDB_Adapter_Mysql 类
 *
 * @link http://qeephp.com/
 * @copyright Copyright (c) 2006-2009 Qeeyuan Inc. {@link http://www.qeeyuan.com}
 * @license New BSD License {@link http://qeephp.com/license/}
 * @version $Id: mysql.php 2403 2009-04-07 03:52:48Z dualface $
 * @package database
 */

/**
 * QDB_Mysql 提供了对 mysql 数据库的支持
 *
 * @author YuLei Liao <liaoyulei@qeeyuan.com>
 * @version $Id: mysql.php 2403 2009-04-07 03:52:48Z dualface $
 * @package database
 */
class QDB_Adapter_Oracle extends QDB_Adapter_Abstract {

    protected $_bind_enabled = false;
    protected $_lastrs = NULL;
    protected $_result_field_name_lower = true;

    function __construct($dsn, $id) {
        if (!is_array($dsn)) {
            $dsn = QDB::parseDSN($dsn);
        }
        parent::__construct($dsn, $id);
        $this->_schema = $dsn['database'];
    }

    function connect($pconnect = false, $force_new = false) {
        if (is_resource($this->_conn)) {
            return;
        }

        $this->_last_err = null;
        $this->_last_err_code = null;

        if (isset($this->_dsn['port']) && $this->_dsn['port'] != '') {
            $host = $this->_dsn['host'] . ':' . $this->_dsn['port'];
        } else {
            $host = $this->_dsn['host'];
        }

        if (!isset($this->_dsn['login'])) {
            $this->_dsn['login'] = '';
        }

        if (!isset($this->_dsn['password'])) {
            $this->_dsn['password'] = '';
        }

        if (isset($this->_dsn['charset']) && $this->_dsn['charset'] != '') {
            $charset = $this->_dsn['charset'];
            if (strtolower($charset) == 'utf8') {
                $charset = 'AL32UTF8';
            }
        }
        $this->_conn = oci_connect($this->_dsn['login'], $this->_dsn['password'], '//' . $host . '/' . $this->_dsn['database'], $charset);
        if (!is_resource($this->_conn)) {
            throw new QDB_Exception('CONNECT DATABASE', print_r(oci_error(), true));
        }
        // 执行一些初始化配置SQL
        if (isset($this->_dsn['initsql'])) {
            $this->execute($this->_dsn['initsql']);
        }
    }

    function pconnect() {
        $this->connect(true);
    }

    function nconnect() {
        $this->connect(false, true);
    }

    function close() {
        if (is_resource($this->_conn)) {
            oci_close($this->_conn);
        }
        parent::_clear();
    }

    function qstr($value) {
        if (is_array($value)) {
            foreach ($value as $offset => $v) {
                $value[$offset] = $this->qstr($v);
            }
            return $value;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? $this->_true_value : $this->_false_value;
        }
        if (is_null($value)) {
            return $this->_null_value;
        }
        if (!($value instanceof QDB_Expr)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }
        return $value->formatToString($this);
    }

    function identifier($name) {
        return ($name != '*') ? "{$name}" : '*';
    }

    function nextID($table_name, $field_name, $start_value = 1) {
        $seq_table_name = $this->qid("{$table_name}_{$field_name}_seq");
        $next_sql = sprintf('UPDATE %s SET id = id + 1', $seq_table_name);
        $start_value = intval($start_value);

        $successed = false;
        try {
            // 首先产生下一个序列值
            $this->execute($next_sql);
            if ($this->affectedRows() > 0) {
                $successed = true;
            }
        } catch (QDB_Exception $ex) {
            // 产生序列值失败，创建序列表
            $this->execute(sprintf('CREATE TABLE %s (id INT NOT NULL)', $seq_table_name));
        }

        if (!$successed) {
            // 没有更新任何记录或者新创建序列表，都需要插入初始的记录
            $getSeqRowQuery = $this->execute(sprintf('SELECT COUNT(*) as CNT FROM %s', $seq_table_name));
            $getSeqRowCount = $getSeqRowQuery->fetchRow();
            if ($getSeqRowCount['CNT'] == 0) {
                $sql = sprintf('INSERT INTO %s VALUES (%s)', $seq_table_name, $start_value);
                $this->execute($sql);
            }
            $this->execute($next_sql);
        }
        // 获得新的序列值
        $getSeqRowQuery = $this->execute(sprintf('SELECT * FROM %s', $seq_table_name));
        $getSeqRowCount = $getSeqRowQuery->fetchRow();
        $this->_insert_id = $getSeqRowCount['id'];
        return $this->_insert_id;
    }

    function createSeq($seq_name, $start_value = 1) {
        $seq_table_name = $this->qid($seq_name);
        $this->execute(sprintf('CREATE TABLE %s (id INT NOT NULL)', $seq_table_name));
        $this->execute(sprintf('INSERT INTO %s VALUES (%s)', $seq_table_name, $start_value));
    }

    function dropSeq($seq_name) {
        $this->execute(sprintf('DROP TABLE %s', $this->qid($seq_name)));
    }

    function insertID() {
        $sqlGetPkColumnName = "select ucc.column_name from user_cons_columns ucc where ucc.constraint_name = (select con.constraint_name from  user_constraints con where con.table_name = '%s' and con.constraint_type = 'P')";
        $rsPkColumnName = $this->execute(sprintf($sqlGetPkColumnName, $this->_lastTablename))->fetchCol();
        $pkColumnName = $rsPkColumnName[0];
        $sql = sprintf("SELECT %s FROM %s WHERE ROWID = :rid", $pkColumnName, $this->_lastTablename);
        $result = oci_parse($this->_conn, $sql);
        oci_bind_by_name($result, ":rid", $this->_lastRowId, -1, OCI_B_ROWID);
        $success = oci_execute($result);
        if ($success) {
            $rs = new QDB_Result_Oracle($result, $this->_fetch_mode);
            $rs->result_field_name_lower = $this->_result_field_name_lower;
            $rsPk = $rs->fetchCol();
            return $rsPk[0];
        }
        return null;
    }

    function affectedRows() {
        return oci_num_rows($this->_lastrs);
    }

    function execute($sql, $inputarr = null) {
        if (is_array($inputarr)) {
            $sql = $this->_fakebind($sql, $inputarr);
        }

        if ($this->_log_enabled) {
            QLog::log('[DB EXECUTE] ' . str_replace("\n", ' ', $sql), QLog::DEBUG);
        }

        if (!$this->_conn) {
            $this->connect();
        }
        $uppersql = strtoupper($sql);
        $returnRowId = (strpos($uppersql, 'INSERT INTO ') === 0);
        if ($returnRowId) {
            $sql .= ' RETURNING ROWID INTO :rid';
        }
        $result = oci_parse($this->_conn, $sql);
        $rowid = null;
        if ($returnRowId) {
            $rowid = oci_new_descriptor($this->_conn, OCI_D_ROWID);
            oci_bind_by_name($result, ":rid", $rowid, -1, OCI_B_ROWID);
            $matchTablename = null;
            preg_match_all('/insert into[\s]+([\w]+)/i', $sql, $matchTablename);
            $tableName = $matchTablename[1][0];
            $this->_lastTablename = $tableName;
        }
        $success = oci_execute($result);
        $this->_lastrs = $result;
        if ($returnRowId) {
            $this->_lastRowId = $rowid;
        }
        if ($success) {
            $rs = new QDB_Result_Oracle($result, $this->_fetch_mode);
            $rs->result_field_name_lower = $this->_result_field_name_lower;
            return $rs;
        } else {
            $this->_last_err = print_r(oci_error($result), true);
            $this->_last_err_code = -1;
            $this->_has_failed_query = true;

            if ($this->_last_err_code == 1062) {
                throw new QDB_Exception_DuplicateKey($sql, $this->_last_err, $this->_last_err_code);
            } else {
                throw new QDB_Exception($sql, $this->_last_err, $this->_last_err_code);
            }
        }
    }

    /**
     * 各数据库驱动分页查询差别较大，需要单独实施
     *      */
    function selectLimit($sql, $offset = 0, $length = 30, array $inputarr = null) {
        if (is_null($offset)) {
            $offset = 0;
        }
        $end = $offset + $length;
        // SQL Server中的分页查询比较麻烦，2005版本以上通过ROW_NUMBER()可以较高性能实现。
        $inline_sql = str_replace(array("\n", "\r"), array('', ''), $sql);
        // 正则找出原有SQL中的表名称、排序字段等信息
        $matchTablename = null;
        preg_match_all('/from[\s]+([\w]+)/i', $inline_sql, $matchTablename);
        $tablename = $matchTablename[1][0];
        // 如果没有排序字段则使用原有SQL中的排序字段
        $matchOrderBy = null;
        preg_match_all('/order\s+by\s+([\w,])+\s{0,1}(asc|desc){0,1}/i', $inline_sql, $matchOrderBy);
        $orderby = '';
        if (count($matchOrderBy[1]) > 0) {
            $orderBy = $matchOrderBy[1][0] . isset($matchOrderBy[2][0]) ? $matchOrderBy[2][0] : '';
        } else {
            $sqlGetPkColumnName = "select ucc.column_name from user_cons_columns ucc where ucc.constraint_name = (select con.constraint_name from  user_constraints con where con.table_name = '%s' and con.constraint_type = 'P')";
            $orderby = $this->execute(sprintf($sqlGetPkColumnName, $tablename))->fetchCol();
            $orderby = 'order by ' . $orderby[0];
        }
        // 获取条件
        $matchWhere = null;
        $where = '';
        preg_match_all('/where\s+(.*)(order by){0,1}\s{0,1}(group by){0,1}/i', $inline_sql, $matchWhere);
        if (count($matchWhere[1]) == 0) {
            preg_match_all('/where\s+(.*)$/i', $inline_sql, $matchWhere);
        }
        if (count($matchWhere[1]) != 0) {
            $where = 'where ' . $matchWhere[1][0];
        }
        // 如果没有排序字段则使用原有SQL中的排序字段
        // 使用ROW_NUMBER()分页时内部SQL不能使用order by 
        $inline_sql = preg_replace('/order\s+by\s+.*?\s+(asc|desc)/i', '', $inline_sql);
        $sql = "
SELECT * FROM {$tablename} 
   WHERE ROWID IN (
      SELECT rid FROM (
        SELECT rid, ROWNUM AS rn FROM (
          SELECT ROWID rid FROM {$tablename} t1 {$where}
          {$orderby}
        ) t1 WHERE ROWNUM<={$end}
      ) t2 WHERE rn>={$offset}
    ) {$orderby}
";
        // 执行最终拼接出的分页查询SQL
        return $this->execute($sql, $inputarr);
    }

    function startTrans() {
        if (!$this->_transaction_enabled) {
            return false;
        }
        if ($this->_trans_count == 0) {
            $this->execute('START TRANSACTION');
            $this->_has_failed_query = false;
        } elseif ($this->_trans_count && $this->_savepoint_enabled) {
            $savepoint = 'savepoint_' . $this->_trans_count;
            $this->execute("SAVEPOINT `{$savepoint}`");
            array_push($this->_savepoints_stack, $savepoint);
        }
        ++$this->_trans_count;
        return true;
    }

    function completeTrans($commit_on_no_errors = true) {
        if ($this->_trans_count == 0) {
            return;
        }
        --$this->_trans_count;
        if ($this->_trans_count == 0) {
            if ($this->_has_failed_query == false && $commit_on_no_errors) {
                $this->execute('COMMIT');
            } else {
                $this->execute('ROLLBACK');
            }
        } elseif ($this->_savepoint_enabled) {
            $savepoint = array_pop($this->_savepoints_stack);
            if ($this->_has_failed_query || $commit_on_no_errors == false) {
                $this->execute("ROLLBACK TO SAVEPOINT `{$savepoint}`");
            }
        }
    }

    function metaColumns($table_name) {
        static $type_mapping = array(
            'number' => 'int4',
            'varchar2' => 'text',
            'nvarchar2' => 'text',
            'bool' => 'bool',
            'boolean' => 'bool',
            'smallint' => 'int2',
            'mediumint' => 'int3',
            'int' => 'int4',
            'integer' => 'int4',
            'bigint' => 'int8',
            'float' => 'float',
            'double' => 'double',
            'doubleprecision' => 'double',
            'float unsigned' => 'float',
            'decimal' => 'dec',
            'dec' => 'dec',
            'date' => 'date',
            'datetime' => 'datetime',
            'timestamp' => 'timestamp',
            'time' => 'time',
            'year' => 'int2',
            'char' => 'char',
            'nchar' => 'char',
            'varchar' => 'varchar',
            'nvarchar' => 'varchar',
            'binary' => 'binary',
            'varbinary' => 'varbinary',
            'tinyblob' => 'blob',
            'tinytext' => 'text',
            'blob' => 'blob',
            'text' => 'text',
            'mediumblob' => 'blob',
            'mediumtext' => 'text',
            'longblob' => 'blob',
            'longtext' => 'text',
            'enum' => 'enum',
            'set' => 'set'
        );
        $sql = sprintf("
select
    lower(col.column_name) field,col.data_type,col.data_type || '(' || col.data_length || ')' type ,col.data_precision precision,
    col.nullable is_nullable, con.constraint_type , col.data_length storesize, 
    con.constraint_type is_identity,
    con.constraint_name auto_incr,
    col.data_scale scale, col.data_default defaultval ,cm.comments commentval
from  user_tab_columns col
left join user_col_comments cm
on cm.table_name = col.TABLE_NAME and cm.column_name = col.COLUMN_NAME
left join user_cons_columns ucc
on ucc.table_name = col.TABLE_NAME and ucc.column_name = col.COLUMN_NAME
left join user_constraints con
on con.table_name = col.TABLE_NAME and con.constraint_name = ucc.constraint_name
where col.table_name = '%s'
        ", $table_name);
        $rs = $this->execute($sql);

        $retarr = array();
        $rs->fetch_mode = QDB::FETCH_MODE_ASSOC;
        $rs->result_field_name_lower = true;
        while (($row = $rs->fetchRow())) {
            
            $field = array();
            //$row['field'] = strtolower($row['field']);
            $field['name'] = $row['field'];
            $row['default'] = $row['defaultval'];
            $field['default'] = $row['defaultval'];
            $type = strtolower($row['type']);

            $field['scale'] = null;
            $query_arr = false;
            if (preg_match('/^(.+)\((\d+),(\d+)/', $type, $query_arr)) {
                $field['type'] = $query_arr[1];
                $field['length'] = is_numeric($query_arr[2]) ? $query_arr[2] : - 1;
                $field['scale'] = is_numeric($query_arr[3]) ? $query_arr[3] : - 1;
            } elseif (preg_match('/^(.+)\((\d+)/', $type, $query_arr)) {
                $field['type'] = $query_arr[1];
                $field['length'] = is_numeric($query_arr[2]) ? $query_arr[2] : - 1;
            } elseif (preg_match('/^(enum)\((.*)\)$/i', $type, $query_arr)) {
                $field['type'] = $query_arr[1];
                $arr = explode(",", $query_arr[2]);
                $field['enums'] = $arr;
                $zlen = max(array_map("strlen", $arr)) - 2; // PHP >= 4.0.6
                $field['length'] = ($zlen > 0) ? $zlen : 1;
            } else {
                $field['type'] = $type;
                $field['length'] = - 1;
            }

            $row['is_rowguidcol'] = null;
            $row['is_seq'] = strpos(strtolower($row['auto_incr']), 'seq') == 0;

            $field['ptype'] = $type_mapping[strtolower($row['data_type'])];
            $field['not_null'] = (strtolower($row['is_nullable']) != 'y');
            $field['pk'] = ($row['is_identity'] == 'P');
            $field['auto_incr'] = $row['is_seq'];
            if ($field['auto_incr']) {
                $field['ptype'] = 'autoincr';
            }
            $field['binary'] = (strpos($type, 'blob') !== false);
            $field['unsigned'] = (strpos($type, 'unsigned') !== false);

            $field['has_default'] = ($row['default'] == null || strlen($row['default']) == 0 );
            if (!$field['binary']) {
                $d = $row['default'];
                if (!is_null($d) && strtolower($d) != 'null') {
                    $field['has_default'] = true;
                    $field['default'] = $d;
                }
            }
            if ($field['type'] == 'tinyint' && $field['length'] == 1) {
                $field['ptype'] = 'bool';
            }
            $field['desc'] = !empty($row['commentval']) ? $row['commentval'] : '';
            if (!is_null($row['default'])) {
                switch ($field['ptype']) {
                    case 'int1':
                    case 'int2':
                    case 'int3':
                    case 'int4':
                        $field['default'] = intval($field['default']);
                        break;
                    case 'float':
                    case 'double':
                    case 'dec':
                        $field['default'] = doubleval($field['default']);
                        break;
                    case 'bool':
                        $field['default'] = (bool) $field['default'];
                }
            }

            $retarr[strtolower($field['name'])] = $field;
        }
        return $retarr;
    }

    function metaTables($pattern = null, $schema = null) {
        $sql = "select table_name from user_tables";
        if ($schema != '') {
            $sql .= " FROM `{$schema}`";
        }
        if ($pattern != '') {
            $sql .= ' LIKE ' . $this->qstr($pattern);
        }
        return $this->getCol($sql);
    }

    function getFullTableName($tablename) {
        return "{$tablename}";
    }

    /**
     * 获得完全限定名
     *
     * @param string $name
     * @param string $alias
     * @param string $as
     *
     * @return string
     */
    function qid($name, $alias = null, $as = null) {
        $name = str_replace('`', '', $name);
        if (strpos($name, '.') === false) {
            $name = $this->identifier($name);
        } else {
            $arr = explode('.', $name);
            foreach ($arr as $offset => $name) {
                if (empty($name)) {
                    unset($arr[$offset]);
                } else {
                    $arr[$offset] = $this->identifier($name);
                }
            }
            $name = $arr[count($arr) - 1];
        }

        if ($alias) {
            return "{$name} {$as} " . $this->identifier($alias);
        } else {
            return $name;
        }
    }

    protected function _fakebind($sql, $inputarr) {
        $arr = explode('?', $sql);
        $sql = array_shift($arr);
        foreach ($inputarr as $value) {
            if (isset($arr[0])) {
                $sql .= $this->qstr($value) . array_shift($arr);
            }
        }
        return $sql;
    }

}
