<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PrimeSetRankingService
{
    private const REQUEST_BATCH_SIZE = 6;
    private const MIN_REQUEST_INTERVAL_MICROSECONDS = 400000;
    private const PRICE_CLUSTER_MIN_ORDERS = 2;
    private const PRICE_OUTLIER_RATIO = 0.65;
    private const PRICE_OUTLIER_ABSOLUTE_GAP = 8;

    private ?int $lastRequestAtMicrotime = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly PartIconResolver $partIconResolver,
    ) {
    }

    /**
     * @return array{generatedAt: \DateTimeImmutable, stats: array<string, int>, sets: list<array<string, mixed>>}
     */
    public function getRankedPrimeSets(bool $forceRefresh = false, ?callable $progress = null): array
    {
        if ($forceRefresh) {
            $this->cache->delete('wf_market.prime_sets.ranking');
        }

        return $this->cache->get('wf_market.prime_sets.ranking', function (ItemInterface $item) use ($progress): array {
            $item->expiresAfter(600);

            $this->reportProgress($progress, 'catalog', 0, null, 'Loading Prime set catalog.');
            $catalog = $this->getPrimeSetCatalog($progress);
            $this->reportProgress($progress, 'prices', 0, count($catalog['requiredSlugs']), 'Fetching live prices.');
            $priceMap = $this->fetchTopOrderPriceMap($catalog['requiredSlugs'], $progress);
            $rankedSets = [];

            foreach ($catalog['sets'] as $set) {
                $setPrices = $priceMap[$set['slug']] ?? ['sell' => null, 'buy' => null, 'sellOrders' => []];
                $setPrice = $setPrices['sell'];
                $partTotal = 0;
                $parts = [];
                $missingPartPrice = false;

                foreach ($set['parts'] as $part) {
                    $partPrices = $priceMap[$part['slug']] ?? ['sell' => null, 'buy' => null, 'sellOrders' => []];
                    $sellPrice = $partPrices['sell'];

                    if ($sellPrice === null) {
                        $missingPartPrice = true;
                    } else {
                        $partTotal += $sellPrice;
                    }

                    $parts[] = [
                        'name' => $part['name'],
                        'slug' => $part['slug'],
                        'imageUrl' => $this->partIconResolver->resolve($set['category'], $part['slug'], $part['imageUrl']),
                        'sellPrice' => $sellPrice,
                        'buyPrice' => $partPrices['buy'],
                        'sellOrders' => $partPrices['sellOrders'],
                    ];
                }

                $partsPrice = $missingPartPrice ? null : $partTotal;
                $bestPrice = $this->bestPrice($setPrice, $partsPrice);

                if ($bestPrice === null) {
                    continue;
                }

                $cheaperMethod = match (true) {
                    $setPrice !== null && $partsPrice !== null && $setPrice < $partsPrice => 'complete-set',
                    $setPrice !== null && $partsPrice !== null && $partsPrice < $setPrice => 'individual-parts',
                    $setPrice !== null && $partsPrice !== null => 'same-price',
                    $setPrice !== null => 'complete-set',
                    default => 'individual-parts',
                };

                $rankedSets[] = [
                    'name' => $set['name'],
                    'slug' => $set['slug'],
                    'category' => $set['category'],
                    'imageUrl' => $set['imageUrl'],
                    'setPrice' => $setPrice,
                    'sellOrders' => $setPrices['sellOrders'],
                    'partsPrice' => $partsPrice,
                    'bestPrice' => $bestPrice,
                    'cheaperMethod' => $cheaperMethod,
                    'savings' => ($setPrice !== null && $partsPrice !== null) ? abs($setPrice - $partsPrice) : null,
                    'parts' => $parts,
                    'wikiLink' => $set['wikiLink'],
                    'tradingTax' => $set['tradingTax'],
                ];
            }

            usort(
                $rankedSets,
                static fn (array $left, array $right): int => [$left['bestPrice'], $left['name']] <=> [$right['bestPrice'], $right['name']]
            );

            return [
                'generatedAt' => new \DateTimeImmutable(),
                'stats' => [
                    'setCount' => count($rankedSets),
                    'warframeCount' => count(array_filter($rankedSets, static fn (array $set): bool => $set['category'] === 'warframe')),
                    'weaponCount' => count(array_filter($rankedSets, static fn (array $set): bool => $set['category'] === 'weapon')),
                    'otherCount' => count(array_filter($rankedSets, static fn (array $set): bool => $set['category'] === 'other')),
                    'warframeCheaperAsSet' => count(array_filter($rankedSets, static fn (array $set): bool => $set['category'] === 'warframe' && $set['cheaperMethod'] === 'complete-set')),
                    'weaponCheaperAsSet' => count(array_filter($rankedSets, static fn (array $set): bool => $set['category'] === 'weapon' && $set['cheaperMethod'] === 'complete-set')),
                    'warframeCheaperAsParts' => count(array_filter($rankedSets, static fn (array $set): bool => $set['category'] === 'warframe' && $set['cheaperMethod'] === 'individual-parts')),
                    'weaponCheaperAsParts' => count(array_filter($rankedSets, static fn (array $set): bool => $set['category'] === 'weapon' && $set['cheaperMethod'] === 'individual-parts')),
                ],
                'sets' => $rankedSets,
            ];
        });
    }

    /**
     * @return array{sets: list<array{name: string, slug: string, category: string, imageUrl: ?string, tradeUrl: string, wikiLink: ?string, tradingTax: int, parts: list<array{name: string, slug: string, imageUrl: ?string}>}>, requiredSlugs: list<string>}
     */
    private function getPrimeSetCatalog(?callable $progress = null): array
    {
        return $this->cache->get('wf_market.prime_sets.catalog', function (ItemInterface $item) use ($progress): array {
            $item->expiresAfter(86400);

            $indexItems = $this->requestJson('items');
            $itemById = [];
            $primeSetSlugs = [];

            foreach ($indexItems as $indexItem) {
                $itemById[$indexItem['id']] = $indexItem;

                $tags = $indexItem['tags'] ?? [];
                if (
                    ($indexItem['slug'] ?? '') !== ''
                    && str_ends_with($indexItem['slug'], '_set')
                    && in_array('prime', $tags, true)
                    && in_array('set', $tags, true)
                ) {
                    $primeSetSlugs[] = $indexItem['slug'];
                }
            }

            sort($primeSetSlugs);
            $this->reportProgress($progress, 'catalog', 0, count($primeSetSlugs), 'Resolving Prime set details.');

            $sets = [];
            $requiredSlugs = [];

            $processedSets = 0;
            foreach ($primeSetSlugs as $slug) {
                $detailPayload = $this->requestPayload(sprintf('items/%s', $slug));
                $detail = $detailPayload['data'] ?? null;

                if (!is_array($detail) || !($detail['setRoot'] ?? false)) {
                    continue;
                }

                $parts = [];
                foreach ($detail['setParts'] ?? [] as $partId) {
                    if (!isset($itemById[$partId])) {
                        continue;
                    }

                    $partIndex = $itemById[$partId];
                    if (($partIndex['slug'] ?? null) === $slug) {
                        continue;
                    }

                    $parts[] = [
                        'name' => $partIndex['i18n']['en']['name'] ?? $partIndex['slug'],
                        'slug' => $partIndex['slug'],
                        'imageUrl' => $this->buildPartAssetUrl($partIndex),
                    ];
                    $requiredSlugs[$partIndex['slug']] = $partIndex['slug'];
                }

                $tags = $detail['tags'] ?? [];

                $sets[] = [
                    'name' => $detail['i18n']['en']['name'] ?? $slug,
                    'slug' => $slug,
                    'category' => $this->detectCategory($tags),
                    'imageUrl' => $this->buildAssetUrl($detail['i18n']['en']['thumb'] ?? $detail['i18n']['en']['icon'] ?? null),
                    'wikiLink' => $detail['i18n']['en']['wikiLink'] ?? null,
                    'tradingTax' => (int) ($detail['tradingTax'] ?? 0),
                    'parts' => $parts,
                ];

                $requiredSlugs[$slug] = $slug;
                $processedSets++;
                $this->reportProgress($progress, 'catalog', $processedSets, count($primeSetSlugs), sprintf('Resolved set %d of %d.', $processedSets, count($primeSetSlugs)));
            }

            return [
                'sets' => $sets,
                'requiredSlugs' => array_values($requiredSlugs),
            ];
        });
    }

    /**
     * @param list<string> $slugs
     * @return array<string, array{sell: ?int, buy: ?int, sellOrders: list<array<string, mixed>>}>
     */
    private function fetchTopOrderPriceMap(array $slugs, ?callable $progress = null): array
    {
        $prices = [];

        $processedSlugs = 0;
        $totalSlugs = count($slugs);

        $slugBatches = array_chunk($slugs, self::REQUEST_BATCH_SIZE);
        $batchCount = count($slugBatches);

        foreach ($slugBatches as $batchIndex => $slugBatch) {
            $payloads = $this->requestPayloadBatch(
                array_combine(
                    $slugBatch,
                    array_map(
                        static fn (string $slug): string => sprintf('orders/item/%s/top', $slug),
                        $slugBatch,
                    ),
                ) ?: [],
            );

            foreach ($slugBatch as $slug) {
                $payload = $payloads[$slug] ?? [];
                $sellOrders = $payload['data']['sell'] ?? [];
                $prices[$slug] = [
                    'sell' => $this->extractTopPrice($sellOrders),
                    'buy' => $this->extractTopPrice($payload['data']['buy'] ?? []),
                    'sellOrders' => $this->buildSellOrderPreview($sellOrders),
                ];

                $processedSlugs++;
                $this->reportProgress($progress, 'prices', $processedSlugs, $totalSlugs, sprintf('Fetched prices for %d of %d items.', $processedSlugs, $totalSlugs));
            }

        }

        return $prices;
    }

    /**
     * @param array<string, string> $requests
     * @return array<string, array<string, mixed>>
     */
    private function requestPayloadBatch(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $responses = [];
        $responseKeys = [];
        foreach ($requests as $key => $url) {
            $this->throttleRequests();
            $response = $this->httpClient->request('GET', $url);
            $responses[] = $response;
            $responseKeys[spl_object_id($response)] = $key;
        }

        $payloads = [];
        foreach ($this->httpClient->stream($responses) as $response => $chunk) {
            if (!$chunk->isLast()) {
                continue;
            }

            $key = $responseKeys[spl_object_id($response)] ?? null;
            if ($key === null) {
                continue;
            }

            try {
                $statusCode = $response->getStatusCode();
                $content = $response->getContent(false);

                if ($statusCode === 429) {
                    throw new \RuntimeException('rate-limit');
                }

                $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                $payloads[$key] = is_array($payload) ? $payload : [];
            } catch (ClientExceptionInterface|TransportExceptionInterface|\JsonException|\RuntimeException) {
                $payloads[$key] = $this->requestPayload($requests[$key]);
            }
        }

        return $payloads;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestJson(string $url): array
    {
        $payload = $this->requestPayload($url);
        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(string $url, int $attempt = 0): array
    {
        try {
            $this->throttleRequests();
            $response = $this->httpClient->request('GET', $url);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($statusCode === 429) {
                throw new \RuntimeException('rate-limit');
            }

            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (ClientExceptionInterface|TransportExceptionInterface|\JsonException|\RuntimeException $exception) {
            if ($attempt < 5) {
                $isRateLimited = $exception instanceof \RuntimeException && $exception->getMessage() === 'rate-limit';
                sleep($isRateLimited ? min(12, 4 + ($attempt * 2)) : $attempt + 2);

                return $this->requestPayload($url, $attempt + 1);
            }

            throw new \RuntimeException(sprintf('Warframe Market response for "%s" could not be parsed after retries.', $url), 0, $exception);
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param list<array<string, mixed>> $orders
     */
    private function extractTopPrice(array $orders): ?int
    {
        $pricesByStatus = [
            'ingame' => [],
            'online' => [],
            'offline' => [],
        ];

        foreach ($orders as $order) {
            if (($order['visible'] ?? true) !== true || !isset($order['platinum'])) {
                continue;
            }

            $price = (int) $order['platinum'];
            if ($price <= 0) {
                continue;
            }

            $status = $order['user']['status'] ?? 'offline';
            $statusBucket = in_array($status, ['ingame', 'online'], true) ? $status : 'offline';
            $pricesByStatus[$statusBucket][] = $price;
        }

        foreach (['ingame', 'online', 'offline'] as $status) {
            if ($pricesByStatus[$status] === []) {
                continue;
            }

            sort($pricesByStatus[$status]);

            return $this->firstSupportedPrice($pricesByStatus[$status]) ?? $pricesByStatus[$status][0];
        }

        return null;
    }

    /**
     * @param list<int> $sortedPrices
     */
    private function firstSupportedPrice(array $sortedPrices): ?int
    {
        $totalPrices = count($sortedPrices);
        if ($totalPrices < self::PRICE_CLUSTER_MIN_ORDERS) {
            return $sortedPrices[0] ?? null;
        }

        foreach ($sortedPrices as $index => $price) {
            $nextPrice = $sortedPrices[$index + 1] ?? null;
            if ($nextPrice === null) {
                return $price;
            }

            $priceGap = $nextPrice - $price;
            $ratioToNext = $nextPrice > 0 ? $price / $nextPrice : 1.0;
            $hasNearbySupport = $ratioToNext >= self::PRICE_OUTLIER_RATIO || $priceGap <= self::PRICE_OUTLIER_ABSOLUTE_GAP;

            if ($hasNearbySupport) {
                return $price;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $orders
     * @return list<array<string, mixed>>
     */
    private function buildSellOrderPreview(array $orders): array
    {
        $preview = [];

        foreach ($orders as $order) {
            if (!isset($order['platinum'])) {
                continue;
            }

            $preview[] = [
                'price' => (int) $order['platinum'],
                'quantity' => (int) ($order['quantity'] ?? 1),
                'status' => (string) ($order['user']['status'] ?? 'offline'),
                'userName' => (string) ($order['user']['ingameName'] ?? 'Unknown'),
                'reputation' => (int) ($order['user']['reputation'] ?? 0),
                'updatedAt' => $order['updatedAt'] ?? null,
            ];
        }

        return $preview;
    }

    /**
     * @param list<string> $tags
     */
    private function detectCategory(array $tags): string
    {
        if (in_array('warframe', $tags, true)) {
            return 'warframe';
        }

        if (in_array('weapon', $tags, true)) {
            return 'weapon';
        }

        return 'other';
    }

    private function buildAssetUrl(?string $assetPath): ?string
    {
        if ($assetPath === null || $assetPath === '') {
            return null;
        }

        return 'https://warframe.market/static/assets/' . ltrim($assetPath, '/');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function buildPartAssetUrl(array $item): ?string
    {
        $i18n = $item['i18n']['en'] ?? [];

        if (!is_array($i18n)) {
            return null;
        }

        return $this->buildAssetUrl($i18n['subIcon'] ?? $i18n['thumb'] ?? $i18n['icon'] ?? null);
    }

    private function reportProgress(?callable $progress, string $phase, int $current, ?int $total, string $message): void
    {
        if ($progress === null) {
            return;
        }

        $progress($phase, $current, $total, $message);
    }

    private function throttleRequests(): void
    {
        $now = (int) floor(microtime(true) * 1000000);

        if ($this->lastRequestAtMicrotime !== null) {
            $elapsed = $now - $this->lastRequestAtMicrotime;
            $remaining = self::MIN_REQUEST_INTERVAL_MICROSECONDS - $elapsed;

            if ($remaining > 0) {
                usleep($remaining);
                $now = (int) floor(microtime(true) * 1000000);
            }
        }

        $this->lastRequestAtMicrotime = $now;
    }

    private function bestPrice(?int $setPrice, ?int $partsPrice): ?int
    {
        return match (true) {
            $setPrice !== null && $partsPrice !== null => min($setPrice, $partsPrice),
            $setPrice !== null => $setPrice,
            $partsPrice !== null => $partsPrice,
            default => null,
        };
    }
}
