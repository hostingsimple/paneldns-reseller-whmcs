<?php

/**
 * A recording stand-in for WHMCS's Capsule query builder.
 *
 * Every Capsule::table() call returns a FakeQuery that records its joins, where
 * clauses and terminal operation (get / first / exists / value / update). A test sets
 * Capsule::$handler to decide what each terminal returns, and inspects
 * Capsule::$queries afterwards.
 */

namespace WHMCS\Database {
    if (!class_exists('WHMCS\\Database\\Capsule', false)) {
        class Capsule
        {
            /** @var list<\FakeQuery> */
            public static array $queries = [];

            /** @var null|\Closure(\FakeQuery $q, string $op, mixed $arg): mixed */
            public static $handler = null;

            public static function table(string $table): \FakeQuery
            {
                $q = new \FakeQuery($table);
                self::$queries[] = $q;
                return $q;
            }

            public static function reset(): void
            {
                self::$queries = [];
                self::$handler = null;
            }

            /** @return list<\FakeQuery> queries that ended in $op (optionally on $table) */
            public static function ops(string $op, ?string $table = null): array
            {
                return array_values(array_filter(
                    self::$queries,
                    fn (\FakeQuery $q) => $q->op === $op && ($table === null || $q->baseTable() === $table)
                ));
            }
        }
    }
}

namespace {
    final class FakeQuery
    {
        public string $table;
        public array $joins = [];
        /** @var list<array{0:string,1:string,2:mixed}> */
        public array $wheres = [];
        public array $selects = [];
        public ?string $op = null;
        public $arg = null;

        public function __construct(string $table)
        {
            $this->table = $table;
        }

        /** "tblhosting as h" → "tblhosting" */
        public function baseTable(): string
        {
            return trim(explode(' as ', $this->table)[0]);
        }

        public function join(...$a): self { $this->joins[] = $a; return $this; }

        public function where(...$a): self
        {
            if (count($a) === 2) {
                $a = [$a[0], '=', $a[1]];
            }
            $this->wheres[] = [$a[0], strtolower((string) $a[1]), $a[2]];
            return $this;
        }

        public function whereIn(string $col, array $vals): self { $this->wheres[] = [$col, 'in', $vals]; return $this; }
        public function whereNotIn(string $col, array $vals): self { $this->wheres[] = [$col, 'not in', $vals]; return $this; }
        public function select(...$a): self { $this->selects = is_array($a[0] ?? null) ? $a[0] : $a; return $this; }
        public function orderBy(...$a): self { return $this; }
        public function limit(int $n): self { return $this; }

        public function get(): FakeCollection { return new FakeCollection((array) ($this->resolve('get', null) ?? [])); }
        public function first() { return $this->resolve('first', null); }
        public function exists(): bool { return (bool) $this->resolve('exists', null); }
        public function value(string $col) { return $this->resolve('value', $col); }
        public function update(array $data): int { return (int) ($this->resolve('update', $data) ?? 0); }

        /** True if a where clause on $col (with $op, and $value if given) exists. */
        public function hasWhere(string $col, string $op = '=', $value = null): bool
        {
            foreach ($this->wheres as [$c, $o, $v]) {
                if ($c === $col && $o === $op && ($value === null || $v === $value)) {
                    return true;
                }
            }
            return false;
        }

        /** The value of the first where clause on $col. */
        public function whereValue(string $col)
        {
            foreach ($this->wheres as [$c, , $v]) {
                if ($c === $col) return $v;
            }
            return null;
        }

        private function resolve(string $op, $arg)
        {
            $this->op  = $op;
            $this->arg = $arg;
            $h = \WHMCS\Database\Capsule::$handler;
            return $h ? $h($this, $op, $arg) : null;
        }
    }

    final class FakeCollection implements IteratorAggregate, Countable
    {
        private array $items;
        public function __construct(array $items) { $this->items = array_values($items); }
        public function all(): array { return $this->items; }
        public function getIterator(): ArrayIterator { return new ArrayIterator($this->items); }
        public function count(): int { return count($this->items); }
    }
}
