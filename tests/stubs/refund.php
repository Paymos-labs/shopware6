<?php

declare(strict_types=1);

class SwRefundDb extends \Doctrine\DBAL\Connection
{
    public $tables = [];
    public $native = ['documents' => 0, 'refunded' => '0', 'stock' => 0, 'state' => 'paid', 'refund_state' => 'open', 'captures' => 0];
    public $level = 0;
    public $owned = true;
    public $busy = false;
    public $snapshots = [];
    public $commits = 0;

    public function __construct() {}
    public function executeStatement($sql, $bind = [])
    {
        if (strpos($sql, 'INSERT IGNORE INTO paymos_refund_installation') === 0) {
            $this->insertOnDuplicate('paymos_refund_installation', ['entity_id' => 1, 'installation_id' => $bind[0]], []);
            return 1;
        }
        if (strpos($sql, 'UPDATE paymos_refund_intent SET withdrawal_id') === 0) {
            return $this->update('paymos_refund_intent', ['withdrawal_id' => $bind[0]], ['operation_id' => $bind[1], 'withdrawal_id' => null]);
        }
        throw new \RuntimeException('Unexpected SQL: ' . $sql);
    }
    public function getTransactionNestingLevel() { return $this->level; }
    public function beginTransaction() { $this->snapshots[] = [$this->tables, $this->native]; $this->level++; }
    public function transactional(callable $action)
    {
        $this->beginTransaction();
        try { $result = $action($this); $this->commit(); return $result; }
        catch (\Throwable $error) { $this->rollBack(); throw $error; }
    }
    public function commit() { array_pop($this->snapshots); $this->level--; $this->commits++; }
    public function rollBack() { list($this->tables, $this->native) = array_pop($this->snapshots); $this->level--; }
    public function insertOnDuplicate($table, $data, $fields)
    {
        if (empty($this->tables[$table])) { $this->tables[$table][] = $data; }
    }
    public function insert($table, $data)
    {
        foreach ($this->tables[$table] ?? [] as $row) {
            foreach (['operation_id', 'external_order_id', 'withdrawal_id', 'native_refund_id'] as $unique) {
                if (isset($data[$unique], $row[$unique]) && $data[$unique] === $row[$unique]) {
                    throw new \RuntimeException('duplicate');
                }
            }
            if (isset($data['request_token'], $row['request_token']) && $data['installation_id'] === $row['installation_id']
                && $data['request_token'] === $row['request_token']) {
                throw new \RuntimeException('duplicate token');
            }
        }
        $this->tables[$table][] = $data;
    }
    public function update($table, $data, $where)
    {
        $count = 0;
        foreach ($this->tables[$table] ?? [] as $i => $row) {
            $match = true;
            foreach ($where as $condition => $value) {
                if (is_int($condition)) {
                    $match = $match && strpos($value, 'IS NULL') !== false && !isset($row['withdrawal_id']);
                } else {
                    $field = trim(explode('=', $condition)[0]);
                    $match = $match && ($row[$field] ?? null) === $value;
                }
            }
            if ($match) { $this->tables[$table][$i] = array_replace($row, $data); $count++; }
        }
        return $count;
    }
    public function fetchOne($sql, $bind = [])
    {
        if (strpos($sql, 'GET_LOCK') !== false) { return $this->busy ? 0 : 1; }
        if (strpos($sql, 'IS_USED_LOCK') !== false) { return $this->owned ? 1 : 0; }
        if (strpos($sql, 'RELEASE_LOCK') !== false) { return 1; }
        if (strpos($sql, 'SELECT ENGINE') === 0) { return 'InnoDB'; }
        if (strpos($sql, 'SELECT installation_id') === 0) { return $this->tables['paymos_refund_installation'][0]['installation_id']; }
        if (strpos($sql, 'SELECT COUNT(*)') === 0) { return count($this->fetchAllAssociative($sql, $bind)); }
        throw new \RuntimeException('Unexpected SQL: ' . $sql);
    }
    public function fetchAssociative($sql, $bind = [])
    {
        $rows = $this->fetchAllAssociative($sql, $bind);
        return count($rows) ? $rows[0] : false;
    }
    public function fetchAllAssociative($sql, $bind = [])
    {
        if (!preg_match('/FROM ([a-z_]+)/', $sql, $match)) { throw new \RuntimeException('Unexpected SQL'); }
        $rows = $this->tables[$match[1]] ?? [];
        if (strpos($sql, 'next_due <= ?') !== false) {
            $rows = array_filter($rows, static function ($r) use ($bind) {
                return $r['next_due'] <= $bind[0] && (count($bind) === 1 || $r['next_due'] > $bind[1]
                    || ($r['next_due'] === $bind[2] && $r['operation_id'] > $bind[3]));
            });
            usort($rows, static function ($a, $b) { return [$a['next_due'], $a['operation_id']] <=> [$b['next_due'], $b['operation_id']]; });
        } else {
            preg_match_all('/([a-z_]+) = \?/', $sql, $conditions);
            foreach ($conditions[1] as $i => $field) {
                $rows = array_filter($rows, static function ($r) use ($field, $bind, $i) { return (string) ($r[$field] ?? '') === (string) $bind[$i]; });
            }
        }
        if (preg_match('/ LIMIT ([0-9]+)/', $sql, $limit)) { $rows = array_slice($rows, 0, (int) $limit[1]); }
        return array_values($rows);
    }
    public function isTableExists($table) { return false; }
}

