<?php

declare(strict_types=1);

namespace Ladybird\WPT\Metrics;

use Ladybird\WPT\Metrics\Entity\Product;
use Ladybird\WPT\Metrics\Entity\Run;

class Storage
{
    private mixed $connection;

    public function __construct(
        string $hostname,
        string $database,
        string $username,
        string $password,
    ) {
        $this->connection = pg_connect(sprintf('host=%s dbname=%s user=%s password=%s', $hostname, $database, $username, $password));
        if ($this->connection === false) {
            throw new \RuntimeException('Could not connect to the database');
        }
    }

    public function __destruct()
    {
        pg_close($this->connection);
    }

    public function beginTransaction(): void
    {
        if (pg_query($this->connection, 'BEGIN') === false) {
            throw new \RuntimeException('Could not begin transaction');
        }
    }

    public function commitTransaction(): void
    {
        if (pg_query($this->connection, 'COMMIT') === false) {
            throw new \RuntimeException('Could not commit transaction');
        }
    }

    private function dateTimeFromTimestamp(string $timestamp): \DateTimeInterface
    {
        $supportedFormats = [
            'Y-m-d H:i:s.u',
            'Y-m-d H:i:s',
        ];
        foreach ($supportedFormats as $format) {
            $dateTime = \DateTimeImmutable::createFromFormat($format, $timestamp, new \DateTimeZone('UTC'));
            if ($dateTime !== false) {
                return $dateTime;
            }
        }
        throw new \RuntimeException(sprintf('Failed to parse timestamp: %s', $timestamp));
    }

    public function createRun(Product $product, int $runId, \DateTimeInterface $createdAt, \DateTimeInterface $timeStart, \DateTimeInterface $timeEnd, string $rawRunMetadata): Run
    {
        $result = pg_query(
            $this->connection,
            sprintf(
                '
                    INSERT INTO wpt_run (run_id, wpt_product_id, created_at, time_start, time_end, raw_run_metadata)
                    VALUES (%d, %d, to_timestamp(%f), to_timestamp(%f), to_timestamp(%f), \'%s\')
                ',
                $runId,
                $product->getId(),
                (float) $createdAt->format('U.u'),
                (float) $timeStart->format('U.u'),
                (float) $timeEnd->format('U.u'),
                pg_escape_string($this->connection, $rawRunMetadata),
            ),
        );
        if ($result === false) {
            throw new \RuntimeException('Could not create run');
        }
        return new Run($runId, $product->getId(), $createdAt, $timeStart, $timeEnd, $rawRunMetadata);
    }

    /**
     * @param array{category: string, total: int, passes: int}[] $categories
     */
    public function createRunCategories(Run $run, array $categories): void
    {
        if (count($categories) === 0) {
            return;
        }
        $values = [];
        foreach ($categories as $category) {
            $values[] = sprintf(
                '(%d, \'%s\', %d, %d)',
                $run->getRunId(),
                pg_escape_string($this->connection, $category['category']),
                $category['total'],
                $category['passes'],
            );
        }
        $result = pg_query(
            $this->connection,
            sprintf(
                'INSERT INTO wpt_run_category (run_id, category, subtest_total, subtest_passes) VALUES %s',
                implode(', ', $values),
            ),
        );
        if ($result === false) {
            throw new \RuntimeException('Could not create run categories');
        }
    }

    public function deleteRun(Run $run): void
    {
        $result = pg_query(
            $this->connection,
            sprintf('DELETE FROM wpt_run WHERE run_id = %d', $run->getRunId()),
        );
        if ($result === false) {
            throw new \RuntimeException('Could not delete run');
        }
    }

    public function deleteRunCategoriesByRun(Run $run): void
    {
        $result = pg_query(
            $this->connection,
            sprintf('DELETE FROM wpt_run_category WHERE run_id = %d', $run->getRunId()),
        );
        if ($result === false) {
            throw new \RuntimeException('Could not delete run categories');
        }
    }

    public function findRunById(int $runId): ?Run
    {
        $result = pg_query_params(
            $this->connection,
            'SELECT wpt_product_id, created_at, time_start, raw_run_metadata FROM wpt_run WHERE run_id = $1',
            [$runId],
        );
        if ($result === false) {
            throw new \RuntimeException('Could not fetch run');
        }
        $row = pg_fetch_assoc($result);
        if ($row === false) {
            return null;
        }
        return new Run(
            $runId,
            (int) $row['wpt_product_id'],
            $this->dateTimeFromTimestamp($row['created_at']),
            $this->dateTimeFromTimestamp($row['time_start']),
            $this->dateTimeFromTimestamp($row['time_end']),
            $row['raw_run_metadata'],
        );
    }

    /**
     * @return iterable<Product>
     */
    public function getProducts(): iterable
    {
        $result = pg_query($this->connection, 'SELECT id, name FROM wpt_product');
        if ($result === false) {
            throw new \RuntimeException('Could not fetch products');
        }
        while ($row = pg_fetch_assoc($result)) {
            yield new Product(
                id: (int) $row['id'],
                name: $row['name'],
            );
        }
    }

    /**
     * @return array{?\DateTimeInterface, ?\DateTimeInterface} Minimum and maximum timestamps
     */
    public function getRunsTimeRange(Product $product): array
    {
        $result = pg_query_params(
            $this->connection,
            '
                SELECT MIN(time_start) AS min_time_start, MAX(time_start) AS max_time_start
                FROM wpt_run
                WHERE wpt_product_id = $1
            ',
            [$product->getId()],
        );
        if ($result === false) {
            throw new \RuntimeException('Could not fetch runs');
        }
        $row = pg_fetch_assoc($result);
        return [
            $row['min_time_start'] === null ? null : $this->dateTimeFromTimestamp($row['min_time_start']),
            $row['max_time_start'] === null ? null : $this->dateTimeFromTimestamp($row['max_time_start']),
        ];
    }
}
