<?php
declare(strict_types=1);
// CLI-only adapter for the small subset of CodeIgniter used by these database tests.
if (PHP_SAPI !== 'cli') { exit; }
class PdoCrmResult
{
    public function __construct(private array $rows) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
    public function result_array(): array { return $this->rows; }
    public function row(): ?object { $r = $this->row_array(); return $r ? (object) $r : null; }
}
class PdoCrm
{
    public bool $save_queries = false, $db_debug = false;
    public string $hostname = '127.0.0.1';
    public ?string $failOn = null;
    private array $where = [], $params = [], $order = [];
    private string $columns = '*', $limitSql = '';
    private int $affected = 0;
    public function __construct(public PDO $pdo, public string $database) {}
    public static function connect(string $database): self
    {
        $port = getenv('NFSE_TEST_MYSQL_PORT') ?: '3307';
        $pdo = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '`'); $pdo->exec('USE `' . $database . '`');
        return new self($pdo, $database);
    }
    private function reset(): void { $this->where = []; $this->params = []; $this->order = []; $this->columns = '*'; $this->limitSql = ''; }
    public function query(string $sql, array $params = [])
    {
        if ($this->failOn && str_contains($sql, $this->failOn)) { $this->failOn = null; return false; }
        try {
            $stmt = $this->pdo->prepare($sql); $stmt->execute($params); $this->affected = $stmt->rowCount();
            return new PdoCrmResult($stmt->columnCount() ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []);
        } catch (PDOException $e) { if ($this->db_debug) { throw $e; } return false; }
    }
    public function where(string $field, $value): self
    {
        if (!preg_match('/^([a-zA-Z0-9_]+)(?: (<=|>=|!=|<|>))?$/D', $field, $m)) { throw new RuntimeException('Unexpected field'); }
        $this->where[] = '`' . $m[1] . '` ' . ($m[2] ?? '=') . ' ?'; $this->params[] = $value; return $this;
    }
    public function where_in(string $field, array $values): self
    { $this->where[] = '`' . $field . '` IN (' . implode(',', array_fill(0, count($values), '?')) . ')'; array_push($this->params, ...$values); return $this; }
    private function condition(): string { return $this->where ? ' WHERE ' . implode(' AND ', $this->where) : ''; }
    public function select(string $columns): self { $this->columns = $columns; return $this; }
    public function order_by(string $column, string $order): self { $this->order[] = '`' . $column . '` ' . $order; return $this; }
    public function limit(int $n, int $offset = 0): self { $this->limitSql = ' LIMIT ' . $offset . ',' . $n; return $this; }
    public function get(string $table)
    { $sql = 'SELECT ' . $this->columns . ' FROM `' . $table . '`' . $this->condition() . ($this->order ? ' ORDER BY ' . implode(',', $this->order) : '') . $this->limitSql; $params = $this->params; $this->reset(); return $this->query($sql, $params); }
    public function update(string $table, array $changes)
    { $sql = 'UPDATE `' . $table . '` SET ' . implode(',', array_map(static fn($k) => '`' . $k . '`=?', array_keys($changes))) . $this->condition(); $params = array_merge(array_values($changes), $this->params); $this->reset(); return $this->query($sql, $params) !== false; }
    public function insert(string $table, array $row)
    { $this->reset(); return $this->query('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row)) !== false; }
    public function delete(string $table)
    { $sql = 'DELETE FROM `' . $table . '`' . $this->condition(); $params = $this->params; $this->reset(); return $this->query($sql, $params) !== false; }
    public function count_all_results(string $table): int { $this->columns = 'COUNT(*) AS total'; return (int) $this->get($table)->row_array()['total']; }
    public function field_exists(string $column, string $table): bool { return (bool) $this->query('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?', [$this->database, $table, $column])->row_array(); }
    public function table_exists(string $table): bool { return (bool) $this->query('SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=? AND table_name=?', [$this->database, $table])->row_array(); }
    public function insert_id(): int { return (int) $this->pdo->lastInsertId(); }
    public function affected_rows(): int { return $this->affected; }
    public function trans_begin(): bool { return $this->pdo->beginTransaction(); }
    public function trans_status(): bool { return true; }
    public function trans_commit(): bool { return $this->pdo->commit(); }
    public function trans_rollback(): bool { return $this->pdo->rollBack(); }
}
