<?php

declare(strict_types=1);

namespace Ladybird\WPT\WPT;

use Nyholm\Psr7\Request;

readonly class Client
{
    private \Http\Client\Curl\Client $curl;

    public function __construct(
        private string $url,
    ) {
        $this->curl = new \Http\Client\Curl\Client(options: [
            CURLOPT_USERAGENT => 'Ladybird WPT API Client/1.0',
        ]);
    }

    private function buildQuery(array $parameters): string
    {
        $queryParts = [];
        foreach ($parameters as $name => $values) {
            $values = is_array($values) ? $values : [$values];
            foreach ($values as $value) {
                if ($value === null) {
                    $queryParts[] = rawurlencode($name);
                } else {
                    $queryParts[] = sprintf('%s=%s', rawurlencode($name), rawurlencode((string) $value));
                }
            }
        }
        return implode('&', $queryParts);
    }

    public function dateTimeFromTimestamp(string $timestamp): \DateTimeInterface
    {
        $supportedFormats = [
            'Y-m-d\TH:i:s.u\Z',
            'Y-m-d\TH:i:s\Z',
        ];
        foreach ($supportedFormats as $format) {
            $dateTime = \DateTimeImmutable::createFromFormat($format, $timestamp, new \DateTimeZone('UTC'));
            if ($dateTime !== false) {
                return $dateTime;
            }
        }
        throw new \RuntimeException(sprintf('Failed to parse timestamp: %s', $timestamp));
    }

    private function executeRequest(string $method, string $url, ?string $body = null): array
    {
        $response = $this->curl->sendRequest(new Request(
            method: $method,
            uri: $url,
            body: $body,
        ));
        if ($response->getStatusCode() === 404) {
            return [];
        } elseif ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('Failed to fetch data from WPT (%d %s)', $response->getStatusCode(), $response->getReasonPhrase()));
        }
        return json_decode($response->getBody()->getContents(), true);
    }

    public function getResultsForRuns(array $runIds): array
    {
        $requestUrl = sprintf('%s/api/search', $this->url);
        return $this->executeRequest('POST', $requestUrl, json_encode(['run_ids' => $runIds]));
    }

    public function getRunsInTimeRange(array $products, array $labels, \DateTimeInterface $timestampFrom, \DateTimeInterface $timestampTo, int $maxCount): array
    {
        $query = $this->buildQuery([
            'label' => $labels,
            'max-count' => $maxCount,
            'from' => $timestampFrom->format('Y-m-d\TH:i:s\Z'),
            'to' => $timestampTo->format('Y-m-d\TH:i:s\Z'),
            'product' => $products,
        ]);
        $requestUrl = sprintf('%s/api/runs?%s', $this->url, $query);
        return $this->executeRequest('GET', $requestUrl);
    }
}
