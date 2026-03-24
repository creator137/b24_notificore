<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\Json;
use PDO;

final class MessageRepository
{
    private const FINAL_STATUSES = [
        'delivered',
        'undelivered',
        'failed',
        'rejected',
        'expired',
        'cancelled',
    ];

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function add(array $message): array
    {
        $row = $this->normalize($message);

        $sql = 'INSERT INTO messages (
            member_id,
            bitrix_message_id,
            provider_message_id,
            provider_reference,
            phone,
            message_text,
            status,
            channel,
            source,
            is_test,
            created_at,
            status_updated_at,
            error_message,
            send_result_json,
            raw_payload_json,
            status_payload_json
        ) VALUES (
            :member_id,
            :bitrix_message_id,
            :provider_message_id,
            :provider_reference,
            :phone,
            :message_text,
            :status,
            :channel,
            :source,
            :is_test,
            :created_at,
            :status_updated_at,
            :error_message,
            :send_result_json,
            :raw_payload_json,
            :status_payload_json
        )';

        $this->pdo->prepare($sql)->execute($row);

        return $this->findById((int)$this->pdo->lastInsertId()) ?? $this->hydrate($row + ['id' => 0]);
    }

    public function all(): array
    {
        return $this->recent(limit: 1000);
    }

    public function recent(?string $memberId = null, int $limit = 20): array
    {
        $sql = 'SELECT * FROM messages';
        $params = [];

        if ($memberId !== null && $memberId !== '') {
            $sql .= ' WHERE member_id = :member_id';
            $params['member_id'] = $memberId;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, (int)$limit);

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map([$this, 'hydrate'], $statement->fetchAll());
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM messages WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByProviderMessageId(string $providerMessageId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM messages WHERE provider_message_id = :provider_message_id ORDER BY id DESC LIMIT 1');
        $statement->execute(['provider_message_id' => $providerMessageId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByProviderReference(string $providerReference): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM messages WHERE provider_reference = :provider_reference ORDER BY id DESC LIMIT 1');
        $statement->execute(['provider_reference' => $providerReference]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function updateByProviderMessageId(string $providerMessageId, array $patch): bool
    {
        $existing = $this->findByProviderMessageId($providerMessageId);

        if ($existing === null) {
            return false;
        }

        return $this->updateById((int)$existing['id'], $patch);
    }

    public function updateById(int $id, array $patch): bool
    {
        $existing = $this->findById($id);

        if ($existing === null) {
            return false;
        }

        $row = $this->normalize(array_replace($existing, $patch));
        $row['id'] = $id;

        $sql = 'UPDATE messages SET
            member_id = :member_id,
            bitrix_message_id = :bitrix_message_id,
            provider_message_id = :provider_message_id,
            provider_reference = :provider_reference,
            phone = :phone,
            message_text = :message_text,
            status = :status,
            channel = :channel,
            source = :source,
            is_test = :is_test,
            created_at = :created_at,
            status_updated_at = :status_updated_at,
            error_message = :error_message,
            send_result_json = :send_result_json,
            raw_payload_json = :raw_payload_json,
            status_payload_json = :status_payload_json
        WHERE id = :id';

        $this->pdo->prepare($sql)->execute($row);

        return true;
    }

    public function findPendingForStatusSync(?string $memberId = null, int $limit = 20): array
    {
        $params = [];
        $placeholders = [];

        foreach (self::FINAL_STATUSES as $index => $status) {
            $key = ':status_' . $index;
            $placeholders[] = $key;
            $params['status_' . $index] = $status;
        }

        $sql = "SELECT * FROM messages WHERE provider_message_id <> '' AND status NOT IN (" . implode(', ', $placeholders) . ')';

        if ($memberId !== null && $memberId !== '') {
            $sql .= ' AND member_id = :member_id';
            $params['member_id'] = $memberId;
        }

        $sql .= ' ORDER BY created_at ASC, id ASC LIMIT ' . max(1, (int)$limit);

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map([$this, 'hydrate'], $statement->fetchAll());
    }

    private function normalize(array $message): array
    {
        $createdAt = (string)($message['ts'] ?? $message['created_at'] ?? date('c'));
        $statusUpdatedAt = (string)($message['status_updated_at'] ?? ($message['status_payload'] ?? null ? date('c') : ''));
        $sendResult = $message['send_result'] ?? [];
        $rawPayload = $message['raw_payload'] ?? [];
        $statusPayload = $message['status_payload'] ?? [];

        return [
            'member_id' => (string)($message['member_id'] ?? ''),
            'bitrix_message_id' => (string)($message['bitrix_message_id'] ?? ''),
            'provider_message_id' => (string)($message['provider_message_id'] ?? ''),
            'provider_reference' => (string)($message['provider_reference'] ?? ''),
            'phone' => (string)($message['phone'] ?? ''),
            'message_text' => (string)($message['message'] ?? $message['message_text'] ?? ''),
            'status' => (string)($message['status'] ?? 'unknown'),
            'channel' => (string)($message['channel'] ?? 'sms'),
            'source' => (string)($message['source'] ?? 'system'),
            'is_test' => $this->toBool($message['is_test'] ?? false) ? 1 : 0,
            'created_at' => $createdAt,
            'status_updated_at' => $statusUpdatedAt,
            'error_message' => (string)($message['error_message'] ?? ''),
            'send_result_json' => Json::encode(is_array($sendResult) ? $sendResult : []),
            'raw_payload_json' => Json::encode(is_array($rawPayload) ? $rawPayload : []),
            'status_payload_json' => Json::encode(is_array($statusPayload) ? $statusPayload : []),
        ];
    }

    private function hydrate(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'ts' => (string)($row['created_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'member_id' => (string)($row['member_id'] ?? ''),
            'bitrix_message_id' => (string)($row['bitrix_message_id'] ?? ''),
            'provider_message_id' => (string)($row['provider_message_id'] ?? ''),
            'provider_reference' => (string)($row['provider_reference'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'message' => (string)($row['message_text'] ?? ''),
            'message_text' => (string)($row['message_text'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'channel' => (string)($row['channel'] ?? ''),
            'source' => (string)($row['source'] ?? ''),
            'is_test' => ((int)($row['is_test'] ?? 0)) === 1,
            'status_updated_at' => (string)($row['status_updated_at'] ?? ''),
            'error_message' => (string)($row['error_message'] ?? ''),
            'send_result' => Json::decodeArray($row['send_result_json'] ?? null),
            'raw_payload' => Json::decodeArray($row['raw_payload_json'] ?? null),
            'status_payload' => Json::decodeArray($row['status_payload_json'] ?? null),
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }
}
