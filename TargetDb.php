<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration;

use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Sequence;
use Exception;

class TargetDb
{
    /**
     * @var \Zend_Db_Adapter_Abstract
     */
    private $db;

    private $config = array();

    private $dryRun = false;

    public function __construct($config)
    {
        $this->config = array_merge(Db::getDatabaseConfig(), $config);
        $this->db = $this->testConnection($this->config);
        return $this->db;
    }

    public function enableDryRun()
    {
        $this->dryRun = true;
    }

    public function beginTransaction()
    {
        $this->db->beginTransaction();
    }

    public function rollBack()
    {
        $this->db->rollBack();
    }

    public function commit()
    {
        $this->db->commit();
    }

    public function fetchRow($sql, $bind = array(), $fetchMode = null)
    {
        return $this->db->fetchRow($sql, $bind, $fetchMode);
    }

    /**
     * @ignore tests only
     */
    public function getDb()
    {
        return $this->db;
    }

    public function getTableColumns($tableName)
    {
        $allColumns = $this->db->fetchAll("SHOW COLUMNS FROM " . $tableName);

        $fields = array();
        foreach ($allColumns as $column) {
            $fields[trim($column['Field'])] = $column;
        }

        return $fields;
    }

    public function doesTableExist($targetDbTableName)
    {
        $foundTable = $this->db->fetchAll("SHOW TABLES LIKE '" . $targetDbTableName . "'");

        return !empty($foundTable);
    }

    public function createArchiveTableIfNeeded($table)
    {
        $sourceDbTableName = Common::prefixTable($table);
        $targetDbTableName = $this->prefixTable($table);

        if (!$this->doesTableExist($targetDbTableName)) {
            $type = 'archive_numeric';
            if (strpos($table, 'blob') !== false) {
                $type = 'archive_blob';
            }
            $createTableSql = DbHelper::getTableCreateSql($type);
            $createTableSql = str_replace($type, $table, $createTableSql);
            $createTableSql = str_replace($sourceDbTableName, $targetDbTableName, $createTableSql);

            if ($this->dryRun) {
                return;
            }

            $this->db->query($createTableSql);
        }
    }

    public function createArchiveId($table)
    {
        if ($this->dryRun) {
            return mt_rand(1, 9999);
        }

        $name = $this->prefixTable($table);
        $sequence = new Sequence($name, $this->db, $this->prefixTable(''));

        if (!$sequence->exists()) {
            $sequence->create();
        }

        return $sequence->getNextId();
    }

    public function prefixTable($table)
    {
        return $this->config['tables_prefix'] . $table;
    }

    public function insert($table, $row)
    {
        $columns = implode('`,`', array_keys($row));
        $fields = Common::getSqlStringFieldsArray($row);

        $tablePrefixed = $this->prefixTable($table);

        $sql = sprintf('INSERT INTO %s (`%s`) VALUES(%s)', $tablePrefixed, $columns, $fields);
        $bind = array_values($row);

        if ($this->dryRun) {
            return mt_rand(1, 999999);
        }

        $this->db->query($sql, $bind);
        $id = $this->db->lastInsertId();

        return (int) $id;
    }

    public function update($table, $columns, $whereColumns)
    {
        if (!empty($columns)) {
            $fields = array();
            $bind = array();
            foreach ($columns as $key => $value) {
                $fields[] = ' ' . $key . ' = ?';
                $bind[] = $value;
            }
            $fields = implode(',', $fields);
            $where = [];
            foreach ($whereColumns as $col => $val) {
                $where[] = '`' . $col .'` = ?';
                $bind[] = $val;
            }
            $where = implode(' AND ', $where);
            $query = sprintf('UPDATE %s SET %s WHERE %s', $this->prefixTable($table), $fields, $where);

            $this->db->query($query, $bind);
        }
    }

    /**
     * @param array $config
     * @return Db\AdapterInterface
     */
    private function testConnection($config)
    {
        try {
            $db = @Db\Adapter::factory($config['adapter'], $config);
        } catch (Exception $e) {
            throw new Exception('Cannot connect to the target database: ' . $e->getMessage(), $e->getCode(), $e);
        }
        return $db;
    }
}