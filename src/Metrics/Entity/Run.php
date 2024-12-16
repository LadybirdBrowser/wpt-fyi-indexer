<?php

declare(strict_types=1);

namespace Ladybird\WPT\Metrics\Entity;

readonly class Run
{
    public function __construct(
        private readonly int $runId,
        private readonly int $productId,
        private readonly \DateTimeInterface $createdAt,
        private readonly \DateTimeInterface $timeStart,
        private readonly \DateTimeInterface $timeEnd,
        private readonly string $rawRunMetadata,
    ) {
    }

    public function getRunId(): int
    {
        return $this->runId;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getTimeStart(): \DateTimeInterface
    {
        return $this->timeStart;
    }

    public function getTimeEnd(): \DateTimeInterface
    {
        return $this->timeEnd;
    }

    public function getRawRunMetadata(): string
    {
        return $this->rawRunMetadata;
    }
}
