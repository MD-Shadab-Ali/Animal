<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the bell.
 *
 * The stored `data` is written by each notification's toDatabase(), and this
 * reads it defensively: rows already in the table were written before these
 * keys existed, and a notification that renders as a blank line is worse than
 * one that never arrived.
 */
class NotificationResource extends JsonResource
{
    /**
     * Which of the shop's things this concerns.
     *
     * A short closed set, because the storefront turns it into an icon and an
     * unknown kind should fall back to a bell rather than to nothing.
     */
    private const KINDS = ['order', 'payment', 'refund', 'booking'];

    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        $kind = $data['kind'] ?? null;

        return [
            'id' => $this->id,
            'kind' => in_array($kind, self::KINDS, true) ? $kind : 'general',
            // A row with no title would draw an empty line in the panel. A
            // class name is a poor title but a legible one, and it only ever
            // shows for rows written before this feature existed.
            'title' => $data['title'] ?? class_basename((string) $this->type),
            'body' => $data['body'] ?? null,
            'url' => $this->storefrontPath($data['url'] ?? null),
            'is_read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Storefront paths only.
     *
     * Rows written for staff point at /admin, which is a different application
     * the buyer cannot open. Sending them there would be a dead end wearing a
     * link, so such a row arrives with no link at all.
     */
    private function storefrontPath(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return str_starts_with($url, '/admin') ? null : $url;
    }
}
